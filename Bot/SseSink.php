<?php

namespace Kanboard\Plugin\AgentChat\Bot;

use ChatParts\Host\HostAdapter;
use ChatParts\Model\Message;
use ChatParts\Model\MessageStatus;
use ChatParts\Model\Part\ReasoningPart;
use ChatParts\Model\Part\TextPart;
use ChatParts\Model\Part\ToolPart;
use ChatParts\Stream\SseWriter;
use ChatParts\Stream\StreamEvents;
use ChatParts\View\Hx;
use ChatParts\View\MessageBubble;
use ChatParts\View\Reasoning;
use ChatParts\View\ToolStrip;
use Closure;

/**
 * A bot run streamed to the asker as HTML over SSE, into the draft bubble draftBubble() drew.
 *
 * That bubble is the stream's only element: it holds the EventSource (`sse-connect`, closed on
 * `done`) and listens for every event with `hx-swap="none"`, so each event is out-of-band
 * fragments addressed by id:
 *
 *  - delta: escaped text appended to the draft's text part;
 *  - reasoning: the first chunk inserts a streaming Reasoning before the text, later ones
 *    append to it;
 *  - parts: the first tool call inserts the ToolStrip before the text, later calls and results
 *    replace it;
 *  - final: the stored message's bubble replaces the draft by its id. Replacing the bubble
 *    removes the EventSource's element, which closes it; `done` follows for any client still
 *    listening.
 *
 * $afterEvent runs after every event (the stream's heartbeat and disconnect check).
 */
final class SseSink implements RunEventSink
{
    /** The events the draft bubble listens for; `done` closes the source instead. */
    public const LISTENS = [StreamEvents::DELTA, StreamEvents::REASONING, StreamEvents::PARTS, StreamEvents::FINAL];

    private bool $reasoningOpen = false;
    /** @var array<string, ToolPart> part id => latest state, in call order */
    private array $tools = [];

    public function __construct(
        private readonly SseWriter $writer,
        private readonly HostAdapter $host,
        private readonly string $messageId,
        private readonly int $runId,
        private readonly ?Closure $afterEvent = null,
    ) {
    }

    /**
     * The asker's empty draft bubble, wired to the stream at $streamUrl: one EventSource on
     * the bubble itself, closed by `done`. It reads as streaming (aria-busy, the composer's
     * stop button) from the start.
     */
    public static function draftBubble(HostAdapter $host, Message $draft, int $runId, string $streamUrl): string
    {
        $message = new Message($draft->id, $draft->room, $draft->participant, MessageStatus::Streaming, [new TextPart(self::textPartId($runId), '')], $draft->metadata, $draft->createdAt);
        $attrs = '';
        foreach (Hx::attrs(['ext' => 'sse', 'sse-connect' => $streamUrl, 'sse-close' => StreamEvents::DONE, 'sse-swap' => implode(',', self::LISTENS), 'swap' => 'none']) as $name => $value) {
            $attrs .= ' '.$name.'="'.$host->escape($value).'"';
        }
        return (string) preg_replace('/^<article\b/', '<article'.$attrs, (string) new MessageBubble($host, $message, true), 1);
    }

    /** The draft's text part id; the stream appends to `#cp-text-<id>`. */
    public static function textPartId(int $runId): string
    {
        return "r$runId-draft-text";
    }

    public function delta(string $text): void
    {
        $this->send(StreamEvents::DELTA, $this->append('#cp-text-'.self::textPartId($this->runId), $text));
    }

    public function reasoning(string $text): void
    {
        $id = "r{$this->runId}-draft-reasoning";
        if ($this->reasoningOpen) {
            $this->send(StreamEvents::REASONING, $this->append("#cp-reasoning-$id .cp-reasoning__text", $text));
            return;
        }
        $this->reasoningOpen = true;
        $this->send(StreamEvents::REASONING, $this->beforeText((string) new Reasoning($this->host, new ReasoningPart($id, $text, null), true)));
    }

    public function toolCall(ToolPart $p): void
    {
        $this->tool($p);
    }

    public function toolResult(ToolPart $p): void
    {
        $this->tool($p);
    }

    public function final(Message $m): void
    {
        $this->send(StreamEvents::FINAL, StreamEvents::oob((string) new MessageBubble($this->host, $m)));
    }

    private function tool(ToolPart $p): void
    {
        $first = $this->tools === [];
        $this->tools[$p->id] = $p;
        $strip = (string) new ToolStrip($this->host, $this->messageId, array_values($this->tools));
        $this->send(StreamEvents::PARTS, $first ? $this->beforeText($strip) : StreamEvents::oob($strip));
    }

    /** $text, escaped, appended to the element $selector names. */
    private function append(string $selector, string $text): string
    {
        return StreamEvents::oob('<div>'.$this->host->escape($text).'</div>', "beforeend:$selector");
    }

    /** $html inserted just before the draft's text part. */
    private function beforeText(string $html): string
    {
        return StreamEvents::oob('<div>'.$html.'</div>', 'beforebegin:#cp-text-'.self::textPartId($this->runId));
    }

    private function send(string $event, string $html): void
    {
        $this->writer->event($event, $html);
        if ($this->afterEvent !== null) {
            ($this->afterEvent)();
        }
    }
}
