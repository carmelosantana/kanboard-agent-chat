<?php

namespace Kanboard\Plugin\AgentChat\Bot;

use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use ChatParts\Model\MessageStatus;
use Kanboard\Core\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Model\MessageModel;
use Kanboard\Plugin\AgentChat\Model\ParticipantResolver;
use Kanboard\Plugin\AgentChat\Model\ProjectSettings;
use Kanboard\Plugin\AgentChat\Model\RoomModel;
use Kanboard\Plugin\AiConnector\Model\ModelCatalog;

/**
 * What one bot answer puts in the model's context window, and the fit both the composer's
 * ring and the run use, so the two agree.
 *
 * The window is AiConnector's ModelCatalog::getContextWindow() for the project's profile (its
 * own fallback for a model it does not know); tokens are ModelCatalog::countTokens(). The
 * window fills in this order: instructions, tool definitions, the drawer's task, history (the
 * last 40 messages, oldest dropped first), sources (held back for tool results and enforced
 * in the run by SourceCap), the new message with its images (IMAGE_TOKENS each, at most
 * MAX_IMAGES), and the reply reserve (replyReserve(): a quarter of the window, at most
 * REPLY_RESERVE). The sources cap is a quarter of the window, at most SOURCES_CAP, and never
 * more than what instructions, tools, task, message and reply reserve leave; history gets
 * what is left after that. So for a question that fits, the segments never add up to more
 * than the window. History gives way first; the message is never trimmed: when instructions,
 * tools, task and message leave no room for the reply reserve, the message is too long for
 * the model (`fits` is false) and the bot does not run.
 *
 * Within one instance the project's profile and people's names are looked up once, so a ring
 * refresh costs a fixed handful of queries, not a few per history row.
 *
 * The task segment is what naming the drawer's task adds to the instructions.
 */
class ContextBudget extends Base
{
    public const HISTORY_LIMIT = 40;
    /** The most tokens of tool results one answer gets, on any window. */
    public const SOURCES_CAP = 8000;
    public const REPLY_RESERVE = 4000;
    public const IMAGE_TOKENS = 1600;
    public const MAX_IMAGES = 4;

    private ?ModelCatalog $catalog = null;
    /** @var array<int, ?string> project id => AiConnector profile */
    private array $profiles = [];
    /** @var array<int, string> user id => display name */
    private array $authors = [];

    /**
     * The ring's breakdown for a message not written yet: the draft is known by its length
     * only (the client never sends its text), so its tokens are countTokens() of a text of
     * that many characters behind the author prefix the run adds. Everything else is what a
     * run would send now.
     *
     * @return array{window: int, segments: list<array{key: string, label: string, tokens: int}>}
     */
    public function breakdown(int $roomId, int $userId, ?int $taskId, int $draftLen, int $imageCount): array
    {
        $projectId = (int) ((new RoomModel($this->container))->get($roomId)['project_id'] ?? 0);
        $scope = new AskerScope($this->container, $userId, $projectId, $taskId);
        $task = $scope->taskId === null ? null : ($this->taskFinderModel->getById($scope->taskId) ?: null);

        $messages = new MessageModel($this->container);
        $rows = $scope->taskId === null
            ? $messages->roomBefore($roomId, PHP_INT_MAX, self::HISTORY_LIMIT - 1)
            : $messages->privateThread($roomId, $userId, $scope->taskId, self::HISTORY_LIMIT - 1);

        $draftLen = max(0, min(MessageModel::MAX_BODY, $draftLen));
        $message = $draftLen === 0 ? 0 : $this->tokens($this->author($userId).': '.str_repeat('x', $draftLen), $projectId);
        $message += max(0, min(self::MAX_IMAGES, $imageCount)) * self::IMAGE_TOKENS;

        $fit = $this->fit($projectId, $userId, $task, ChatAgent::readTools($scope, new SourceRegistry()), $this->history($rows), $message);
        return ['window' => $fit['window'], 'segments' => $fit['segments']];
    }

    /** Whether a room question (the message body) fits the project's model at all. */
    public function questionFits(int $projectId, int $userId, string $body): bool
    {
        $tools = ChatAgent::readTools(new AskerScope($this->container, $userId, $projectId), new SourceRegistry());
        return $this->fit($projectId, $userId, null, $tools, [], $this->tokens($this->author($userId).': '.$body, $projectId))['fits'];
    }

    /**
     * The fit: the instructions to send, the history that fits (oldest dropped first; never
     * opening with the bot's turn), whether the message fits at all, and the segments.
     *
     * @param ?array $task the drawer's task row
     * @param list<ToolInterface> $tools
     * @param list<MessageInterface> $history oldest first
     * @return array{window: int, sources_cap: int, reply_reserve: int, instructions: string, history: list<MessageInterface>, fits: bool, segments: list<array{key: string, label: string, tokens: int}>}
     */
    public function fit(int $projectId, int $userId, ?array $task, array $tools, array $history, int $messageTokens): array
    {
        $window = $this->catalog()->getContextWindow($this->profile($projectId));
        $plain = $this->instructions($projectId, $userId, null);
        $instructions = $task === null ? $plain : $this->instructions($projectId, $userId, $task);
        $instructionTokens = $this->tokens($plain, $projectId);
        $taskTokens = max(0, $this->tokens($instructions, $projectId) - $instructionTokens);
        $toolTokens = $this->tokens((string) json_encode(array_map(static fn (ToolInterface $t): array => $t->toFunctionSchema(), $tools)), $projectId);
        $fixed = $instructionTokens + $toolTokens + $taskTokens + $messageTokens;

        $reserve = self::replyReserve($window);
        $sourcesCap = min(self::sourcesCap($window), max(0, $window - $reserve - $fixed));
        $room = $window - $reserve - $sourcesCap - $fixed;
        $sizes = array_map(fn (MessageInterface $m): int => $this->tokens((string) $m->content(), $projectId), $history);
        while ($history !== [] && array_sum($sizes) > $room) {
            array_shift($history);
            array_shift($sizes);
        }
        while ($history !== [] && $history[0] instanceof AssistantMessage) {
            array_shift($history);
            array_shift($sizes);
        }

        return [
            'window' => $window,
            'sources_cap' => $sourcesCap,
            'reply_reserve' => $reserve,
            'instructions' => $instructions,
            'history' => $history,
            'fits' => $fixed <= $window - $reserve,
            'segments' => [
                ['key' => 'instructions', 'label' => t('Instructions'), 'tokens' => $instructionTokens],
                ['key' => 'tools', 'label' => t('Tools'), 'tokens' => $toolTokens],
                ['key' => 'task', 'label' => t('Task'), 'tokens' => $taskTokens],
                ['key' => 'history', 'label' => t('History'), 'tokens' => array_sum($sizes)],
                ['key' => 'sources_cap', 'label' => t('Sources (cap)'), 'tokens' => $sourcesCap],
                ['key' => 'message', 'label' => t('Message and images'), 'tokens' => $messageTokens],
                ['key' => 'reply_reserve', 'label' => t('Reply reserve'), 'tokens' => $reserve],
            ],
        ];
    }

    /** Output tokens kept free for the answer: a quarter of the window, at most REPLY_RESERVE. */
    public static function replyReserve(int $window): int
    {
        return max(0, min(self::REPLY_RESERVE, intdiv($window, 4)));
    }

    /** Tool-result tokens one answer may use at most: a quarter of the window, at most SOURCES_CAP. */
    public static function sourcesCap(int $window): int
    {
        return max(0, min(self::SOURCES_CAP, intdiv($window, 4)));
    }

    /**
     * Thread rows (oldest first) as the model's turns: people's and agents' messages as user
     * turns prefixed with their name, the bot's as assistant turns; empty and unfinished ones
     * left out, and the row at $skip (the question) too.
     *
     * @return list<MessageInterface>
     */
    public function history(array $rows, ?int $skip = null): array
    {
        $history = [];
        foreach ($rows as $i => $row) {
            $text = trim((string) $row['body_text']);
            if ($i === $skip || $text === '' || ! in_array($row['status'], [MessageStatus::Done->value, MessageStatus::Stopped->value], true)) {
                continue;
            }
            $history[] = match ($row['sender_kind']) {
                'bot' => new AssistantMessage($text),
                'human', 'agent' => new UserMessage($this->author((int) $row['sender_id']).': '.$text),
                default => null,
            };
        }
        return array_values(array_filter($history));
    }

    /** The bot's instructions for this asker in this project, naming the drawer's task if any. */
    public function instructions(int $projectId, int $userId, ?array $task): string
    {
        $asker = $this->userModel->getById($userId) ?: [];
        return Instructions::build(
            $this->projectModel->getById($projectId) ?: [],
            ['name' => (string) ($asker['name'] ?? ''), 'username' => (string) ($asker['username'] ?? ''), 'role' => $this->roleLabel($userId, $projectId)],
            $task,
        );
    }

    /** Estimated tokens of $text for the project's model. */
    public function tokens(string $text, int $projectId): int
    {
        return $this->catalog()->countTokens($text, $this->profile($projectId));
    }

    public function author(int $userId): string
    {
        return $this->authors[$userId] ??= (new ParticipantResolver($this->container))->forUser($userId)->name;
    }

    /** The asker's project role as Kanboard names it ("Project Member", a custom role, "Administrator"). */
    public function roleLabel(int $userId, int $projectId): string
    {
        $role = (string) $this->projectUserRoleModel->getUserRole($projectId, $userId);
        if ($role === '') {
            return ($this->userModel->getById($userId)['role'] ?? '') === Role::APP_ADMIN ? t('Administrator') : '';
        }
        return (string) ($this->projectRoleModel->getList($projectId)[$role] ?? $role);
    }

    private function profile(int $projectId): ?string
    {
        if (! array_key_exists($projectId, $this->profiles)) {
            $this->profiles[$projectId] = (new ProjectSettings($this->container, $projectId))->aiProfile();
        }
        return $this->profiles[$projectId];
    }

    private function catalog(): ModelCatalog
    {
        return $this->catalog ??= new ModelCatalog($this->container);
    }
}
