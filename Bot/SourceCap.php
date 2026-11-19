<?php

namespace Kanboard\Plugin\AgentChat\Bot;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Closure;

/**
 * Holds one run's tool results to the sources cap: once the results passed to the model add
 * up to more than $cap tokens, a further call is not run and the model gets TEXT instead.
 */
final class SourceCap
{
    public const TEXT = 'Source budget reached; answer with what you have.';

    private int $used = 0;

    /** @param Closure(string): int $count estimated tokens of a result's content */
    public function __construct(private readonly int $cap, private readonly Closure $count)
    {
    }

    /**
     * The tools, each held to this cap.
     *
     * @param list<ToolInterface> $tools
     * @return list<ToolInterface>
     */
    public function wrap(array $tools): array
    {
        return array_map(fn (ToolInterface $tool): ToolInterface => new class($tool, $this) implements ToolInterface {
            public function __construct(private readonly ToolInterface $tool, private readonly SourceCap $cap)
            {
            }

            public function name(): string
            {
                return $this->tool->name();
            }

            public function description(): string
            {
                return $this->tool->description();
            }

            public function parameters(): array
            {
                return $this->tool->parameters();
            }

            public function execute(array $input): ToolResult
            {
                return $this->cap->execute($this->tool, $input);
            }

            public function toFunctionSchema(): array
            {
                return $this->tool->toFunctionSchema();
            }
        }, $tools);
    }

    public function execute(ToolInterface $tool, array $input): ToolResult
    {
        if ($this->used > $this->cap) {
            return ToolResult::success(self::TEXT);
        }
        $result = $tool->execute($input);
        $this->used += ($this->count)($result->content);
        return $result;
    }
}
