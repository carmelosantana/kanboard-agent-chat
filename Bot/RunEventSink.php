<?php

namespace Kanboard\Plugin\AgentChat\Bot;

use ChatParts\Model\Message;
use ChatParts\Model\Part\ToolPart;

/**
 * Where a bot run's events go while it runs: the SSE stream for a live answer, the store for a
 * run nobody watches. final() comes last, once, with the stored message.
 */
interface RunEventSink
{
    /** Answer text as it arrives (raw, unescaped). */
    public function delta(string $text): void;

    public function reasoning(string $text): void;

    /** A tool call has started (state running). */
    public function toolCall(ToolPart $p): void;

    /** The same tool row (same id) finished: done, error or denied. */
    public function toolResult(ToolPart $p): void;

    public function final(Message $m): void;
}
