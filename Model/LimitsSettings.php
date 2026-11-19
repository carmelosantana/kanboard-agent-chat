<?php

namespace Kanboard\Plugin\AgentChat\Model;

use CarmeloSantana\PHPAgents\Agent\RunLimits;

/**
 * The bot's global run limits, in Kanboard's config table under `agentchat_*`. A stored value
 * that is missing, not a number, or not above zero reads as the default, so no limit can be
 * switched off (php-agents reads 0 as unlimited).
 *
 * The runner applies the per-run ones through runLimits(); the concurrency and hourly quotas
 * are checked before a run starts, by the run lifecycle.
 */
final class LimitsSettings
{
    public const MAX_ITERATIONS = 'agentchat_max_iterations';
    public const MAX_TOOL_CALLS_PER_RUN = 'agentchat_max_tool_calls_per_run';
    public const MAX_TOOL_CALLS_PER_TURN = 'agentchat_max_tool_calls_per_turn';
    public const MAX_RUN_SECONDS = 'agentchat_max_run_seconds';
    public const MAX_RESPONSE_BYTES = 'agentchat_max_response_bytes';
    public const MAX_CONCURRENT_PER_USER = 'agentchat_max_concurrent_per_user';
    public const MAX_CONCURRENT_INSTANCE = 'agentchat_max_concurrent_instance';
    public const QUESTIONS_PER_HOUR = 'agentchat_questions_per_hour';

    /** Every limit with its label (translated where shown), in the settings form's order. */
    public const FIELDS = [
        self::QUESTIONS_PER_HOUR => 'Questions per person per hour',
        self::MAX_CONCURRENT_PER_USER => 'Answers at once per person',
        self::MAX_CONCURRENT_INSTANCE => 'Answers at once on this instance',
        self::MAX_RUN_SECONDS => 'Time per answer (seconds)',
        self::MAX_ITERATIONS => 'Steps per answer',
        self::MAX_TOOL_CALLS_PER_RUN => 'Tool calls per answer',
        self::MAX_TOOL_CALLS_PER_TURN => 'Tool calls per step',
        self::MAX_RESPONSE_BYTES => 'Answer size (bytes)',
    ];

    public function __construct(private $container)
    {
    }

    public function maxIterations(): int
    {
        return $this->int(self::MAX_ITERATIONS, 6);
    }

    public function maxToolCallsPerRun(): int
    {
        return $this->int(self::MAX_TOOL_CALLS_PER_RUN, 8);
    }

    public function maxToolCallsPerTurn(): int
    {
        return $this->int(self::MAX_TOOL_CALLS_PER_TURN, 4);
    }

    public function maxRunSeconds(): float
    {
        $value = $this->read(self::MAX_RUN_SECONDS);
        return is_numeric($value) && (float) $value > 0 ? (float) $value : 90.0;
    }

    public function maxResponseBytes(): int
    {
        return $this->int(self::MAX_RESPONSE_BYTES, 262144);
    }

    public function maxConcurrentPerUser(): int
    {
        return $this->int(self::MAX_CONCURRENT_PER_USER, 1);
    }

    public function maxConcurrentInstance(): int
    {
        return $this->int(self::MAX_CONCURRENT_INSTANCE, 4);
    }

    public function questionsPerHour(): int
    {
        return $this->int(self::QUESTIONS_PER_HOUR, 30);
    }

    /** @return array<string, int> each limit's value now, by key (as FIELDS) */
    public function values(): array
    {
        return [
            self::QUESTIONS_PER_HOUR => $this->questionsPerHour(),
            self::MAX_CONCURRENT_PER_USER => $this->maxConcurrentPerUser(),
            self::MAX_CONCURRENT_INSTANCE => $this->maxConcurrentInstance(),
            self::MAX_RUN_SECONDS => (int) ceil($this->maxRunSeconds()),
            self::MAX_ITERATIONS => $this->maxIterations(),
            self::MAX_TOOL_CALLS_PER_RUN => $this->maxToolCallsPerRun(),
            self::MAX_TOOL_CALLS_PER_TURN => $this->maxToolCallsPerTurn(),
            self::MAX_RESPONSE_BYTES => $this->maxResponseBytes(),
        ];
    }

    /**
     * Saves every limit in $posted (key => value) when each is a whole number of 1 or more;
     * otherwise saves nothing and returns the error per key. A missing key is an error too.
     *
     * @return array<string, string> errors by key, empty when saved
     */
    public function save(array $posted): array
    {
        $errors = [];
        $values = [];
        foreach (self::FIELDS as $key => $label) {
            $value = $posted[$key] ?? null;
            $value = is_string($value) ? trim($value) : '';
            if (! ctype_digit($value) || (int) $value < 1 || strlen(ltrim($value, '0')) > 9) {
                $errors[$key] = t('%s must be a whole number of 1 or more.', t($label));
                continue;
            }
            $values[$key] = (string) (int) $value;
        }
        if ($errors === []) {
            $this->container['configModel']->save($values);
        }
        return $errors;
    }

    /**
     * The php-agents limits for one run; $maxInputTokens caps the new question (0: no cap).
     * $elapsedSeconds is run time already spent (a retry): the time limit is what is left, at
     * least 1 ms, since php-agents reads 0 as no limit.
     */
    public function runLimits(int $maxInputTokens = 0, float $elapsedSeconds = 0.0): RunLimits
    {
        return new RunLimits(
            maxToolCallsPerTurn: $this->maxToolCallsPerTurn(),
            maxToolCallsPerRun: $this->maxToolCallsPerRun(),
            maxRunSeconds: max(0.001, $this->maxRunSeconds() - max(0.0, $elapsedSeconds)),
            maxResponseBytes: $this->maxResponseBytes(),
            maxInputTokens: max(0, $maxInputTokens),
        );
    }

    private function int(string $key, int $default): int
    {
        $value = $this->read($key);
        return ctype_digit($value) && (int) $value > 0 ? (int) $value : $default;
    }

    private function read(string $key): string
    {
        return trim((string) $this->container['configModel']->get($key, ''));
    }
}
