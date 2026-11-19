<?php

declare(strict_types=1);

namespace ChatParts\View;

use ChatParts\Host\HostAdapter;
use ChatParts\Model\Message;
use ChatParts\Model\MessageStatus;
use ChatParts\Model\Part\FilePart;
use ChatParts\Model\Part\ReasoningPart;
use ChatParts\Model\Part\SourcePart;
use ChatParts\Model\Part\TextPart;
use ChatParts\Model\Part\ToolPart;

/**
 * One message of the transcript, in any status: avatar, a meta row (name, kind badge, time,
 * copy), the parts in their stored order, then the sources row and the receipt. All tool calls
 * share one ToolStrip, placed where the first call was; source parts are not drawn in place but
 * feed the citations and the sources row; data parts are the host's to draw. While streaming
 * the article is `aria-busy`, and the stream's final frame replaces it whole. `data-id` is the
 * message id: chatparts.js reads it for the poll cursor and to drop a bubble that arrives twice.
 */
final class MessageBubble extends Component
{
    public function __construct(HostAdapter $host, private readonly Message $m, private readonly bool $streaming = false)
    {
        parent::__construct($host);
    }

    public function render(): string
    {
        $kind = Avatar::kind($this->m->participant);
        $sources = array_values(array_filter($this->m->parts, static fn ($p): bool => $p instanceof SourcePart));
        $tools = array_values(array_filter($this->m->parts, static fn ($p): bool => $p instanceof ToolPart));

        $content = '';
        foreach ($this->m->parts as $part) {
            $content .= match (true) {
                $part instanceof TextPart => new Response($this->host, $part, $this->streaming, $sources),
                $part instanceof ReasoningPart => new Reasoning($this->host, $part, $this->streaming),
                $part === ($tools[0] ?? null) => new ToolStrip($this->host, $this->m->id, $tools, $sources),
                $part instanceof FilePart => $this->file($part),
                default => '',
            };
        }

        $body = $this->tag('header', ['class' => 'cp-msg__meta'], $this->meta())
            . $this->tag('div', ['class' => 'cp-msg__content'], $content)
            . new SourceList($this->host, $sources)
            . $this->receipt($kind);

        return $this->tag(
            'article',
            ['id' => 'cp-msg-' . $this->m->id, 'class' => 'cp-msg cp-msg--' . $kind, 'data-id' => $this->m->id, 'data-state' => $this->m->status->value, 'aria-busy' => $this->streaming ? 'true' : null],
            new Avatar($this->host, $this->m->participant) . $this->tag('div', ['class' => 'cp-msg__body'], $body),
        );
    }

    private function meta(): string
    {
        $at = $this->m->createdAt;
        $copy = $this->streaming ? '' : $this->tag(
            'button',
            ['type' => 'button', 'class' => 'cp-msg__action', 'data-action' => 'copy', 'aria-label' => $this->host->t('Copy message')],
            (string) new Icon($this->host, 'copy'),
        );

        return $this->tag('span', ['class' => 'cp-msg__name'], $this->e($this->m->participant->name))
            . new Badge($this->host, $this->m->participant)
            . $this->tag('time', ['class' => 'cp-msg__time', 'datetime' => date('c', $at)], $this->e(date('H:i', $at)))
            . ($copy === '' ? '' : $this->tag('span', ['class' => 'cp-msg__actions'], $copy));
    }

    /** A thumbnail for an image with a safe thumb URL, otherwise the file name; a link when the URL is safe. */
    private function file(FilePart $f): string
    {
        $thumb = $f->thumbUrl !== null && str_starts_with($f->mime, 'image/') ? $this->u($f->thumbUrl) : '';
        $inner = $thumb === '' ? $this->e($f->name) : '<img class="cp-file__thumb" src="' . $thumb . '" alt="' . $this->a($f->name) . '">';
        $href = $this->u($f->url);

        return $href === '' ? $this->tag('span', ['class' => 'cp-file'], $inner) : '<a class="cp-file" href="' . $href . '">' . $inner . '</a>';
    }

    /** Agents and bots get a receipt once they are no longer running; a stopped or failed run says why. */
    private function receipt(string $kind): string
    {
        if (!in_array($kind, ['agent', 'bot'], true) || in_array($this->m->status, [MessageStatus::Submitted, MessageStatus::Streaming], true) || $this->streaming) {
            return '';
        }
        $meta = $this->m->metadata;
        if ($kind === 'bot') {
            $meta['bot'] = $this->m->participant->name;
        }
        $meta += match ($this->m->status) {
            MessageStatus::Stopped => ['finish_reason' => 'stopped'],
            MessageStatus::Error => ['finish_reason' => 'error'],
            default => [],
        };

        return (string) new Receipt($this->host, $meta);
    }
}
