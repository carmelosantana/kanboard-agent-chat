<?php

namespace Kanboard\Plugin\AgentChat\Bot;

use CarmeloSantana\PHPAgents\Agent\CallbackObserver;
use CarmeloSantana\PHPAgents\Agent\Output;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
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
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Bot\Tools\GetTaskTool;
use Kanboard\Plugin\AgentChat\Bot\Tools\SearchCommentsTool;
use Kanboard\Plugin\AgentChat\Bot\Tools\SearchKnowledgeTool;
use Kanboard\Plugin\AgentChat\Bot\Tools\SearchTasksTool;
use Kanboard\Plugin\AgentChat\Model\AccessPolicy;
use Kanboard\Plugin\AgentChat\Model\LimitsSettings;
use Kanboard\Plugin\AgentChat\Model\MessageModel;
use Kanboard\Plugin\AgentChat\Model\ParticipantResolver;
use Kanboard\Plugin\AgentChat\Model\ProjectSettings;
use Kanboard\Plugin\AgentChat\Model\RoomModel;
use Kanboard\Plugin\AgentChat\Model\RunModel;
use Kanboard\Plugin\AiConnector\Model\ModelCatalog;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use Pimple\Container;

/**
 * Runs one bot answer: the run row names the bot's draft message, the room and the asker.
 *
 * Input: in a task drawer only the asker's private thread on that task, in the room only the
 * last 40 room messages (never a private one), oldest dropped first to fit the model's input
 * budget. Everyone's messages go in as user turns prefixed with their name, the bot's earlier
 * answers as assistant turns, the asker's latest message last. Every tool reads as the asker
 * (AskerScope); an asker who can no longer view the project gets no run at all.
 *
 * Events go to the sink as they happen (text, reasoning, tool rows); then the draft is
 * rewritten as the final message (reasoning, tool rows, the answer, the cited sources) with
 * status done, stopped or error, the run row is closed and the sink gets final(). `[n]`
 * markers that match no retrieved source are dropped. On a stop or a run limit the text
 * streamed so far is kept; on any failure the message says only that the bot could not
 * answer, and the real error goes to the log, never to the message or the run row.
 *
 * Callers claim the run first (RunModel::claim for a stream; runToCompletion claims by id), so
 * a run is answered once. The provider is AiConnector's for the project's profile, with its
 * HTTP requests held to IDLE_TIMEOUT between chunks and the run time limit plus
 * PROVIDER_SLACK_SECONDS in all; a provider in the container under PROVIDER_OVERRIDE replaces
 * it (tests).
 */
class BotRunner extends Base
{
    public const HISTORY_LIMIT = 40;
    /** Output tokens kept free of input; a later context budget replaces this. */
    private const REPLY_RESERVE = 4000;
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

    /** The project's provider, its requests bounded by the run time limit. */
    public function provider(?string $profile): ProviderInterface
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
        $sources = new SourceRegistry();
        $output = null;
        $model = '';
        $errorCode = null;

        try {
            if (! (new AccessPolicy($this->container))->canView($askerId, $projectId)) {
                $errorCode = 'forbidden';
            } elseif (! $token->isCancelled()) {
                $taskId = $draft['visibility'] === 'private' && $draft['task_id'] ? (int) $draft['task_id'] : null;
                $scope = new AskerScope($this->container, $askerId, $projectId, $taskId);
                $tools = [new SearchTasksTool($scope, $sources), new GetTaskTool($scope, $sources), new SearchCommentsTool($scope, $sources), new SearchKnowledgeTool($scope, $sources)];
                $task = $scope->taskId === null ? null : $this->taskFinderModel->getById($scope->taskId);
                $instructions = Instructions::build(
                    $this->projectModel->getById($projectId) ?: [],
                    ['name' => (string) ($asker['name'] ?? ''), 'username' => (string) ($asker['username'] ?? ''), 'role' => $this->roleLabel($askerId, $projectId)],
                    $task ?: null,
                );

                [$history, $question] = $this->conversation($messages, $draft, $askerId);
                if ($question === null) {
                    $errorCode = 'no_question';
                } else {
                    $profile = (new ProjectSettings($this->container, $projectId))->aiProfile();
                    $budget = $this->inputBudget($profile);
                    $history = $this->fit($history, $budget, $this->tokens($instructions) + $this->tokens(json_encode(array_map(static fn (ToolInterface $t) => $t->toFunctionSchema(), $tools))) + $this->tokens((string) $question->content()));

                    $provider = $this->providerOverride ?? $this->provider($profile);
                    $model = $provider->getModel();
                    $limits = new LimitsSettings($this->container);
                    $agent = new ChatAgent($provider, $instructions, $tools, $limits->maxIterations(), $limits->runLimits($budget), $token);
                    $agent->attach(new CallbackObserver(fn (string $event, mixed $data) => $this->observe($runId, $event, $data, $sink)));
                    $conversation = new Conversation();
                    foreach ($history as $message) {
                        $conversation->add($message);
                    }
                    $output = $agent->run($question, $conversation);
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
            'asked_by' => ['name' => (string) (($asker['name'] ?? '') ?: ($asker['username'] ?? '')), 'role' => $this->roleLabel($askerId, $projectId)],
        ];
        if ($token->stopRequest() === DbCancellationToken::STOPPED_BY_ASKER && $status === MessageStatus::Stopped) {
            $metadata['stopped_by'] = $metadata['asked_by']['name'];
        }

        // The run row first: when the reaper (or a failed stream) already ended the run, its
        // error stands and the stored message is what the sink gets.
        if ((new RunModel($this->container))->finish($runId, $status->value, $metadata['usage'] + ['finish_reason' => $finishReason, 'duration_ms' => $durationMs] + ($errorCode === null ? [] : ['error_code' => $errorCode]))) {
            $messages->update((int) $draft['id'], ['parts' => $this->finalParts($runId, $text, $cited), 'status' => $status->value, 'metadata' => $metadata]);
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
     * The thread before the draft as php-agents messages, and the asker's latest message in it
     * (null when there is none).
     *
     * @return array{0: list<MessageInterface>, 1: ?UserMessage}
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
            return [[], null];
        }

        $history = [];
        foreach ($rows as $i => $row) {
            $text = trim((string) $row['body_text']);
            if ($i === $question || $text === '' || ! in_array($row['status'], [MessageStatus::Done->value, MessageStatus::Stopped->value], true)) {
                continue;
            }
            $history[] = match ($row['sender_kind']) {
                'bot' => new AssistantMessage($text),
                'human', 'agent' => new UserMessage($this->author((int) $row['sender_id']).': '.$text),
                default => null,
            };
        }

        return [array_values(array_filter($history)), new UserMessage($this->author($askerId).': '.$rows[$question]['body_text'])];
    }

    /**
     * Drops the oldest history until it fits next to the fixed input; a history never opens
     * with the bot's turn.
     *
     * @param list<MessageInterface> $history
     * @return list<MessageInterface>
     */
    private function fit(array $history, int $budget, int $fixed): array
    {
        $tokens = array_map(fn (MessageInterface $m): int => $this->tokens((string) $m->content()), $history);
        while ($history !== [] && $budget > 0 && $fixed + array_sum($tokens) > $budget) {
            array_shift($history);
            array_shift($tokens);
        }
        while ($history !== [] && $history[0] instanceof AssistantMessage) {
            array_shift($history);
        }
        return $history;
    }

    /** Input tokens the model takes once the reply is reserved; 0 when unknown (no cap). */
    private function inputBudget(?string $profile): int
    {
        try {
            return class_exists(ModelCatalog::class) ? (new ModelCatalog($this->container))->getInputBudget($profile, self::REPLY_RESERVE) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function tokens(string $text): int
    {
        return (int) ceil(mb_strlen($text, 'UTF-8') / 4);
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
            return [MessageStatus::Error, 'error', $failed, $errorCode];
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

    /**
     * Reasoning, tool rows, the answer, the cited sources. A tool row still running when the
     * run ended (a stop between call and result) is marked as not run.
     *
     * @param list<SourcePart> $sources
     * @return list<Part>
     */
    private function finalParts(int $runId, string $text, array $sources): array
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
        return [...$parts, ...$sources];
    }

    private function author(int $userId): string
    {
        return (new ParticipantResolver($this->container))->forUser($userId)->name;
    }

    /** The asker's project role as Kanboard names it ("Project Member", a custom role, "Administrator"). */
    private function roleLabel(int $userId, int $projectId): string
    {
        $role = (string) $this->projectUserRoleModel->getUserRole($projectId, $userId);
        if ($role === '') {
            return ($this->userModel->getById($userId)['role'] ?? '') === Role::APP_ADMIN ? t('Administrator') : '';
        }
        return (string) ($this->projectRoleModel->getList($projectId)[$role] ?? $role);
    }
}
