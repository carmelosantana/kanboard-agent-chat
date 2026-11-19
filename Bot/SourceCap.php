<?php

namespace Kanboard\Plugin\AgentChat\Bot;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Closure;

/**
 * Holds one run's tool results to the sources cap ($cap tokens, as $count estimates them).
 * Results go in whole while they fit what is left of the cap. The one that does not fit is cut
 * to what is left, as JSON: `{"untrusted": true, "truncated": true, "note": …, "partial": "<the
 * result's first characters>"}` (cut on a character boundary, the wrapper counted in). When too
 * little is left for any partial, and for every call after the cap is used up, the tool is not
 * run and the model gets TEXT. So the results the model gets total at most $cap tokens; the
 * only overhead past it is TEXT itself (about 13 tokens) once per call after the cap.
 *
 * With the run's SourceRegistry given, a cut result keeps only the new sources whose `"n":`
 * the model still sees (items are listed in number order, so that is the first few), and a
 * result replaced by TEXT after its tool ran keeps none, so no unseen number can be cited.
 */
final class SourceCap
{
    public const TEXT = 'Source budget reached; answer with what you have.';
    private const NOTE = 'Cut to the source budget: only the start of this result is here. Answer with what you have.';

    private int $used = 0;

    /** @param Closure(string): int $count estimated tokens of a result's content */
    public function __construct(private readonly int $cap, private readonly Closure $count, private readonly ?SourceRegistry $sources = null)
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
        $left = $this->cap - $this->used;
        if ($left <= 0) {
            return ToolResult::success(self::TEXT);
        }
        $known = $this->sources === null ? 0 : count($this->sources->all());
        $result = $tool->execute($input);
        $tokens = ($this->count)($result->content);
        if ($tokens <= $left) {
            $this->used += $tokens;
            return $result;
        }

        $this->used = $this->cap;
        $cut = $this->cut($result->content, $left);
        if ($this->sources !== null) {
            preg_match_all('/"n":(\d+)[,}]/', $cut === null ? '' : (string) json_decode($cut, true)['partial'], $m);
            $this->sources->forgetAfter(max([$known, ...array_map('intval', $m[1])]));
        }
        return $cut === null
            ? ToolResult::success(self::TEXT)
            : new ToolResult(ToolResultStatus::Success, $cut, mimeType: 'application/json');
    }

    /** The longest start of $content whose JSON wrapper is at most $tokens; null when none is. */
    private function cut(string $content, int $tokens): ?string
    {
        $content = mb_scrub($content, 'UTF-8');
        $wrap = static fn (string $partial): string => (string) json_encode(
            ['untrusted' => true, 'truncated' => true, 'note' => self::NOTE, 'partial' => $partial],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        // The wrapper only grows with the partial, so the longest that fits is a binary search.
        [$lo, $hi] = [0, mb_strlen($content, 'UTF-8')];
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            if (($this->count)($wrap(mb_substr($content, 0, $mid, 'UTF-8'))) <= $tokens) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        return $lo === 0 ? null : $wrap(mb_substr($content, 0, $lo, 'UTF-8'));
    }
}
