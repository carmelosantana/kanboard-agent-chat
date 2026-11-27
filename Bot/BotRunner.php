<?php

namespace Kanboard\Plugin\AgentChat\Bot;

use CarmeloSantana\PHPAgents\Agent\CallbackObserver;
use CarmeloSantana\PHPAgents\Agent\Output;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Image\ImageInputException;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\AbstractProvider;
use CarmeloSantana\PHPAgents\Provider\RequestLimits;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Tool\DoneTool;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use ChatParts\Model\Message;
use ChatParts\Model\MessageStatus;
use ChatParts\Model\Part\Part;
use ChatParts\Model\Part\ReasoningPart;
use ChatParts\Model\Part\SourcePart;
use ChatParts\Model\Part\TextPart;
use ChatParts\Model\Part\ToolPart;
use ChatParts\Model\ToolState;
use InvalidArgumentException;
use Kanboard\Core\Base;
use Kanboard\Plugin\AgentChat\Model\AccessPolicy;
use Kanboard\Plugin\AgentChat\Model\AiGate;
use Kanboard\Plugin\AgentChat\Model\FileModel;
use Kanboard\Plugin\AgentChat\Model\LimitsSettings;
use Kanboard\Plugin\AgentChat\Model\MessageModel;
use Kanboard\Plugin\AgentChat\Model\ParticipantResolver;
use Kanboard\Plugin\AgentChat\Model\ProjectSettings;
use Kanboard\Plugin\AgentChat\Model\RoomModel;
use Kanboard\Plugin\AgentChat\Model\RunModel;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use Pimple\Container;

/**
 * Runs one bot answer: the run row names the bot's draft message, the room and the asker.
 *
 * Input: in a task drawer only the asker's private thread on that task, in the room only the
 * last 40 room messages (never a private one), fitted to the model's context window by
 * ContextBudget (oldest history dropped first; a question too long for the model gets no
 * provider call, only "Your message is too long for this model."). Everyone's messages go in
 * as user turns prefixed with their name, the bot's earlier answers as assistant turns, the
 * asker's latest message last, with its images when the project's botImages() setting is on
 * (StorageImageResolver; an image it refuses leaves the question as text). A provider that
 * fails before any output on a question with images is asked once more with the text alone;
 * either way the answer ends with "The bot could not see the images.". Tool results are held to
 * the fit's sources cap (SourceCap).
 * Every tool reads as the asker (AskerScope); an asker who can no longer view the project gets
 * no run at all.
 *
 * Events go to the sink as they happen (text, reasoning, tool rows); then the draft is
 * rewritten as the final message (reasoning, tool rows, the answer, the cited sources) with
 * status done, stopped or error, the run row is closed and the sink gets final(). `[n]`
 * markers that match no retrieved source are dropped. On a stop or a run limit the text
 * streamed so far is kept; on any failure the message says only that the bot could not
 * answer, and the real error goes to the log, never to the message or the run row.
 *
 * Callers claim the run first (RunModel::claim for a stream; runToCompletion claims by id), so
 * a run is answered once. The provider is AiConnector's for the profile its routing picks for
 * agentchat.bot in the room's project (AiGate::botProfile; no ready profile ends the run as
 * "unavailable" with no provider call), with its HTTP requests held to IDLE_TIMEOUT between
 * chunks and the run time limit plus PROVIDER_SLACK_SECONDS in all; a provider in the
 * container under PROVIDER_OVERRIDE replaces it (tests).
 */
class BotRunner extends Base
{
    public const HISTORY_LIMIT = ContextBudget::HISTORY_LIMIT;
    private const MAX_TOOL_ERROR = 200;
    public const PROVIDER_OVERRIDE = 'agentchat.bot_provider';
    public const IDLE_TIMEOUT = 30.0;
    public const PROVIDER_SLACK_SECONDS = 10.0;

    private string $streamed = '';
    private string $reasoning = '';
    private int $toolSeq = 0;
    /** @var array<string, ToolPart> part id => latest state, in call order */
    private array $toolParts = [];
    /** @var array<string, list<string>> provider call id => part ids awaiting a result */
    private array $awaiting = [];

    public function __construct(Container $container, private readonly ?ProviderInterface $providerOverride = null)
    {
        parent::__construct($container);
    }

    /** A runner with the container's provider override, if any. */
    public static function forContainer(Container $container): self
    {
        return new self($container, $container->offsetExists(self::PROVIDER_OVERRIDE) ? $container[self::PROVIDER_OVERRIDE] : null);
    }

    /**
     * Claims the pending run and answers it without a live stream, keeping the draft current
     * in the store. A run that is not pending (claimed elsewhere, finished) is not run again:
     * its message is returned as stored.
     */
    public function runToCompletion(int $runId): Message
    {
        $run = $this->runRow($runId);
        if (! (new RunModel($this->container))->claimById($runId)) {
            $messages = new MessageModel($this->container);
            return $messages->toChatPartsMessage($messages->getById((int) $run['message_id']) ?? throw new InvalidArgumentException("Run #$runId has no message."));
        }
        return $this->run($runId, new StoreSink($this->container, (int) $run['message_id'], $runId));
    }

    /**
     * The provider for the project's bot: the profile AiConnector routes agentchat.bot to for
     * the project (or the override), or null when routing has no ready profile.
     */
    public function providerFor(int $projectId): ?ProviderInterface
    {
        $profile = AiGate::botProfile($this->container, $projectId);
        if ($profile === '') {
            return null;
        }
        return $this->providerOverride ?? $this->provider($profile);
    }

    /** The profile's provider, its requests bounded by the run time limit. */
    public function provider(string $profile): ProviderInterface
    {
        $provider = (new ProviderRegistry($this->container))->buildProvider($profile);
        if ($provider instanceof AbstractProvider) {
            $provider = $provider->withRequestLimits(new RequestLimits(
                idleTimeout: self::IDLE_TIMEOUT,
                maxDuration: (new LimitsSettings($this->container))->maxRunSeconds() + self::PROVIDER_SLACK_SECONDS,
            ));
        }
        return $provider;
    }

    public function run(int $runId, RunEventSink $sink): Message
    {
        $run = $this->runRow($runId);
        $messages = new MessageModel($this->container);
        $draft = $messages->getById((int) $run['message_id']) ?? throw new InvalidArgumentException("Run #$runId has no message.");
        if (in_array($run['status'], ['done', 'stopped', 'error'], true)) {
            $final = $messages->toChatPartsMessage($draft);
            $sink->final($final);
            return $final;
        }

        $this->streamed = $this->reasoning = '';
        $this->toolSeq = 0;
        $this->toolParts = $this->awaiting = [];
        $startedNs = hrtime(true);
        $this->db->table('agentchat_runs')->eq('id', $runId)->update(['status' => 'running', 'started_at' => (int) ($run['started_at'] ?: time())]);

        $askerId = (int) $run['asked_by'];
        $asker = $this->userModel->getById($askerId) ?: [];
        $room = (new RoomModel($this->container))->get((int) $run['room_id']) ?? [];
        $projectId = (int) ($room['project_id'] ?? 0);
        $token = new DbCancellationToken($this->container, $runId);
        $budget = new ContextBudget($this->container);
        $sources = new SourceRegistry();
        $output = null;
        $imagesUnseen = false;
        $model = '';
        $errorCode = null;

        try {
            if (! (new AccessPolicy($this->container))->canView($askerId, $projectId)) {
                $errorCode = 'forbidden';
            } elseif ($token->isCancelled()) {
                // Stopped before it started: outcome() reads the stop.
            } elseif (($provider = $this->providerFor($projectId)) === null) {
                $errorCode = 'unavailable';
            } else {
                $taskId = $draft['visibility'] === 'private' && $draft['task_id'] ? (int) $draft['task_id'] : null;
                $scope = new AskerScope($this->container, $askerId, $projectId, $taskId);
                $tools = ChatAgent::readTools($scope, $sources);
                $task = $scope->taskId === null ? null : ($this->taskFinderModel->getById($scope->taskId) ?: null);

                [$history, $questionText, $questionId] = $this->conversation($messages, $draft, $askerId);
                $images = $questionId === null ? [] : $this->imageRefs($questionId, $projectId);
                $fit = $questionText === null ? null : $budget->fit($projectId, $askerId, $task, $tools, $history, $budget->tokens($questionText, $projectId) + count($images) * ContextBudget::IMAGE_TOKENS);
                if ($fit === null) {
                    $errorCode = 'no_question';
                } elseif (! $fit['fits']) {
                    $errorCode = 'too_long';
                } else {
                    $history = $fit['history'];
                    $sourceCap = new SourceCap($fit['sources_cap'], static fn (string $text): int => $budget->tokens($text, $projectId), $sources);
                    $model = $provider->getModel();
                    [$question, $imageBytesTokens] = $this->question($questionText, $images, $askerId, (int) $run['room_id'], $runId);
                    $limits = new LimitsSettings($this->container);
                    $ask = function (UserMessage $question, int $extraInputTokens) use ($provider, $fit, $sourceCap, $tools, $limits, $token, $runId, $sink, $history, $startedNs): Output {
                        $elapsed = (hrtime(true) - $startedNs) / 1e9;
                        $agent = new ChatAgent($provider, $fit['instructions'], $sourceCap->wrap($tools), $limits->maxIterations(), $limits->runLimits(max(1, $fit['window'] - $fit['reply_reserve']) + $extraInputTokens, $elapsed), $token);
                        $agent->attach(new CallbackObserver(fn (string $event, mixed $data) => $this->observe($runId, $event, $data, $sink)));
                        $conversation = new Conversation();
                        foreach ($history as $message) {
                            $conversation->add($message);
                        }
                        return $agent->run($question, $conversation);
                    };
                    $output = $ask($question, $imageBytesTokens);
                    // A provider that refuses image input (no vision, an image it will not take)
                    // fails before it says anything: ask once more with the text alone, within
                    // the run's remaining time. A failure after any output is final.
                    if ($imageBytesTokens > 0 && $output->finishReason === AgentFinishReason::Error && $this->nothingEmitted()
                        && ! $token->isCancelled() && (hrtime(true) - $startedNs) / 1e9 < $limits->maxRunSeconds()) {
                        $this->logger->warning("AgentChat: bot run #$runId asks again without images after: ".mb_substr($output->content, 0, self::MAX_TOOL_ERROR, 'UTF-8'));
                        $imageBytesTokens = 0;
                        $output = $ask(new UserMessage($questionText), 0);
                    }
                    $imagesUnseen = $images !== [] && $imageBytesTokens === 0;
                    $model = $output->model !== '' ? $output->model : $model;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error("AgentChat: bot run #$runId failed: ".get_class($e).': '.$e->getMessage());
            $errorCode = 'exception';
        }

        [$status, $finishReason, $text, $errorCode] = $this->outcome($output, $token, $errorCode);
        [$text, $cited] = $this->cite($text, $sources, $runId);
        $usage = $output?->usage ?? new Usage();
        $durationMs = intdiv(hrtime(true) - $startedNs, 1_000_000);
        $metadata = [
            'bot' => (new ParticipantResolver($this->container))->bot()->name,
            'model' => $model,
            'tool_count' => count($this->toolParts),
            'finish_reason' => $finishReason,
            'duration_ms' => $durationMs,
            'usage' => ['prompt_tokens' => $usage->promptTokens, 'completion_tokens' => $usage->completionTokens, 'total_tokens' => $usage->totalTokens],
            'asked_by' => ['id' => (string) $askerId, 'name' => (string) (($asker['name'] ?? '') ?: ($asker['username'] ?? '')), 'role' => $budget->roleLabel($askerId, $projectId)],
        ];
        if ($imagesUnseen) {
            $metadata['images_unseen'] = true;
        }
        if ($token->stopRequest() === DbCancellationToken::STOPPED_BY_ASKER && $status === MessageStatus::Stopped) {
            $metadata['stopped_by'] = $metadata['asked_by']['name'];
        }

        // The run row first: when the reaper (or a failed stream) already ended the run, its
        // error stands and the stored message is what the sink gets.
        if ((new RunModel($this->container))->finish($runId, $status->value, $metadata['usage'] + ['finish_reason' => $finishReason, 'duration_ms' => $durationMs] + ($errorCode === null ? [] : ['error_code' => $errorCode]))) {
            $messages->update((int) $draft['id'], ['parts' => $this->finalParts($runId, $text, $cited, $imagesUnseen), 'status' => $status->value, 'metadata' => $metadata]);
        }

        $final = $messages->toChatPartsMessage($messages->getById((int) $draft['id']));
        $sink->final($final);
        return $final;
    }

    private function runRow(int $runId): array
    {
        return $this->db->table('agentchat_runs')->eq('id', $runId)->findOne() ?: throw new InvalidArgumentException("No bot run #$runId.");
    }

    /**
     * The thread before the draft as php-agents messages, and the asker's latest message in it:
     * its text with the author prefix and its id (both null when there is none).
     *
     * @return array{0: list<MessageInterface>, 1: ?string, 2: ?int}
     */
    private function conversation(MessageModel $messages, array $draft, int $askerId): array
    {
        $draftId = (int) $draft['id'];
        $rows = $draft['visibility'] === 'private'
            ? array_values(array_filter(
                $messages->privateThread((int) $draft['room_id'], $askerId, (int) $draft['task_id'], self::HISTORY_LIMIT + 1),
                static fn (array $row): bool => (int) $row['id'] < $draftId,
            ))
            : $messages->roomBefore((int) $draft['room_id'], $draftId, self::HISTORY_LIMIT);
        $rows = array_slice($rows, -self::HISTORY_LIMIT);

        $question = null;
        foreach ($rows as $i => $row) {
            if ((int) $row['sender_id'] === $askerId && in_array($row['sender_kind'], ['human', 'agent'], true)) {
                $question = $i;
            }
        }
        if ($question === null) {
            return [[], null, null];
        }

        $budget = new ContextBudget($this->container);
        return [$budget->history($rows, $question), $budget->author($askerId).': '.$rows[$question]['body_text'], (int) $rows[$question]['id']];
    }

    /**
     * References to the question's images, when the project sends images to the bot.
     *
     * @return list<string>
     */
    private function imageRefs(int $messageId, int $projectId): array
    {
        if (! (new ProjectSettings($this->container, $projectId))->botImages()) {
            return [];
        }
        $files = array_slice((new FileModel($this->container))->forMessage($messageId), 0, ContextBudget::MAX_IMAGES);
        return array_map(static fn (array $f): string => StorageImageResolver::reference((int) $f['id']), $files);
    }

    /**
     * The question as a php-agents message, with its images when there are any and the resolver
     * accepts every one (otherwise the text alone, and the log says why; the answer then says the
     * bot could not see them), and how many tokens
     * php-agents' bytes / 4 estimate puts on the images' data URIs. The fit already counted each
     * image as ContextBudget::IMAGE_TOKENS, so that many more go on the run's input limit: the
     * limit then holds the text to what the fit allowed instead of refusing any sizeable image.
     *
     * @param list<string> $images
     * @return array{0: UserMessage, 1: int}
     */
    private function question(string $text, array $images, int $askerId, int $roomId, int $runId): array
    {
        $plain = new UserMessage($text);
        if ($images === []) {
            return [$plain, 0];
        }
        try {
            $message = UserMessage::withImages($text, $images, new StorageImageResolver($this->container, $askerId, $roomId));
        } catch (ImageInputException $e) {
            $this->logger->warning("AgentChat: bot run #$runId goes without images: ".$e->getMessage());
            return [$plain, 0];
        }
        return [$message, max(0, $this->estimate($message) - $this->estimate($plain))];
    }

    private function estimate(UserMessage $message): int
    {
        $single = new Conversation();
        $single->add($message);
        return $single->estimateTokens();
    }

    private function observe(int $runId, string $event, mixed $data, RunEventSink $sink): void
    {
        switch ($event) {
            case 'agent.text_delta':
                $this->streamed .= $data;
                $sink->delta($data);
                break;
            case 'agent.reasoning':
                $this->reasoning .= $data;
                $sink->reasoning($data);
                break;
            case 'agent.tool_call':
                if ($data instanceof ToolCall && $data->name !== DoneTool::NAME) {
                    $part = new ToolPart("r$runId-tool-".++$this->toolSeq, $data->name, $data->arguments, ToolState::Running, null, null);
                    $this->toolParts[$part->id] = $part;
                    $this->awaiting[$data->id][] = $part->id;
                    $sink->toolCall($part);
                }
                break;
            case 'agent.tool_result':
                $partId = $data instanceof ToolResult && $data->callId !== null && isset($this->awaiting[$data->callId]) ? array_shift($this->awaiting[$data->callId]) : null;
                if ($partId !== null) {
                    $part = $this->finished($this->toolParts[$partId], $data);
                    $this->toolParts[$partId] = $part;
                    $sink->toolResult($part);
                }
                break;
            case 'agent.error':
                // The provider's own words (already secret-redacted by php-agents): log only.
                $this->logger->error("AgentChat: bot run #$runId: ".(is_string($data) ? $data : json_encode($data)));
                break;
            case 'agent.warning':
            case 'agent.budget_warning':
                $this->logger->debug("AgentChat: bot run #$runId $event: ".(is_string($data) ? $data : json_encode($data)));
                break;
        }
    }

    private function finished(ToolPart $part, ToolResult $result): ToolPart
    {
        if ($result->status === ToolResultStatus::Success) {
            return new ToolPart($part->id, $part->name, $part->input, ToolState::Done, $this->summary($result), null);
        }
        $error = mb_substr($result->content, 0, self::MAX_TOOL_ERROR, 'UTF-8');
        $state = str_starts_with($result->content, 'Denied by policy:') ? ToolState::Denied : ToolState::Error;
        return new ToolPart($part->id, $part->name, $part->input, $state, null, $error);
    }

    /** "3 results", "Task #12", or nothing. */
    private function summary(ToolResult $result): ?string
    {
        $data = json_decode($result->content, true);
        if (is_array($data['results'] ?? null)) {
            $n = count($data['results']);
            return $n === 1 ? t('1 result') : t('%d results', $n);
        }
        if (isset($data['task']['task_id'])) {
            return t('Task #%d', (int) $data['task']['task_id']);
        }
        return null;
    }

    /**
     * Status, finish reason (as chatparts names it), the text to store and the run's error code.
     *
     * @return array{0: MessageStatus, 1: string, 2: string, 3: ?string}
     */
    private function outcome(?Output $output, DbCancellationToken $token, ?string $errorCode): array
    {
        $failed = t('The bot could not answer.');
        if ($errorCode !== null) {
            $text = match ($errorCode) {
                'too_long' => t('Your message is too long for this model.'),
                'unavailable' => t('The bot is not available here.'),
                default => $failed,
            };
            return [MessageStatus::Error, 'error', $text, $errorCode];
        }
        // The token's last read, not a new one: a stop the agent never saw ends nothing.
        if ($token->stopRequest() !== 0) {
            return [MessageStatus::Stopped, 'stopped', $this->streamedOr(t('The bot stopped before answering.')), null];
        }
        if ($output === null) {
            return [MessageStatus::Error, 'error', $failed, 'exception'];
        }
        return match ($output->finishReason) {
            AgentFinishReason::Stop, AgentFinishReason::Done => [MessageStatus::Done, 'stop', $output->content, null],
            AgentFinishReason::BudgetExhausted => [MessageStatus::Stopped, 'budget_exhausted', $this->streamedOr(t('The bot reached its run budget before answering.')), null],
            AgentFinishReason::MaxIterations => [MessageStatus::Stopped, 'max_iterations', $this->streamedOr(t('The bot reached its step limit before answering.')), null],
            AgentFinishReason::Refusal, AgentFinishReason::ContentFilter, AgentFinishReason::ContextWindowExceeded => [MessageStatus::Error, $output->finishReason->value, $failed, $output->finishReason->value],
            AgentFinishReason::EmptyResponse => [MessageStatus::Error, 'error', $failed, 'empty_response'],
            AgentFinishReason::Error => [MessageStatus::Error, 'error', $failed, 'provider_error'],
        };
    }

    private function streamedOr(string $fallback): string
    {
        return trim($this->streamed) === '' ? $fallback : $this->streamed;
    }

    /**
     * The text without `[n]` markers that match no source, and the matched sources renumbered
     * under run-scoped ids. Markers in code spans and fences are left alone and cite nothing.
     *
     * @return array{0: string, 1: list<SourcePart>}
     */
    private function cite(string $text, SourceRegistry $sources, int $runId): array
    {
        $cited = [];
        $pieces = preg_split('/(```.*?```|`[^`\n]*`)/s', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        foreach ($pieces as $i => $piece) {
            if ($i % 2 === 1) {
                continue;
            }
            $pieces[$i] = (string) preg_replace_callback('/ ?\[([1-9][0-9]?)\]/', static function (array $m) use ($sources, &$cited): string {
                $source = $sources->resolve((int) $m[1]);
                if ($source === null) {
                    return '';
                }
                $cited[$source->n] = $source;
                return $m[0];
            }, $piece);
        }
        ksort($cited);

        return [implode('', $pieces), array_values(array_map(
            static fn (SourcePart $s): SourcePart => new SourcePart("r$runId-s{$s->n}", $s->n, $s->title, $s->url, $s->kind, $s->ref),
            $cited,
        ))];
    }

    /** Whether the run has sent the sink nothing yet: no text, reasoning or tool row. */
    private function nothingEmitted(): bool
    {
        return $this->streamed === '' && $this->reasoning === '' && $this->toolParts === [];
    }

    /**
     * Reasoning, tool rows, the answer, a note when the question's images did not reach the
     * model, the cited sources. A tool row still running when the run ended (a stop between
     * call and result) is marked as not run.
     *
     * @param list<SourcePart> $sources
     * @return list<Part>
     */
    private function finalParts(int $runId, string $text, array $sources, bool $imagesUnseen = false): array
    {
        $parts = [];
        if ($this->reasoning !== '') {
            $parts[] = new ReasoningPart("r$runId-reasoning", $this->reasoning, null);
        }
        foreach ($this->toolParts as $part) {
            $parts[] = $part->state === ToolState::Running
                ? new ToolPart($part->id, $part->name, $part->input, ToolState::Error, null, t('Not run.'))
                : $part;
        }
        $parts[] = new TextPart("r$runId-t1", $text);
        if ($imagesUnseen) {
            $parts[] = new TextPart("r$runId-note", '_'.t('The bot could not see the images.').'_');
        }
        return [...$parts, ...$sources];
    }
}
