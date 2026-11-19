<?php

declare(strict_types=1);

namespace ChatParts\View;

use ChatParts\Host\HostAdapter;
use ChatParts\Model\Message;

/**
 * The transcript: a log of message bubbles that polls for what came after the last one shown,
 * plus the "jump to latest" pill and a polite live region for announcements. The log itself is
 * `aria-live="off"` (each poll would otherwise read the whole batch); chatparts.js announces
 * finished answers into `#cp-announce` instead. Polling is a plain `every 4s`: an htmx trigger
 * filter would need eval, so the script pauses polling while the tab is hidden, finding the
 * log by `data-cp-poll`.
 */
final class Conversation extends Component
{
    /** @param list<Message> $messages oldest first */
    public function __construct(
        HostAdapter $host,
        private readonly array $messages,
        private readonly string $pollUrl,
        private readonly int $afterId,
    ) {
        parent::__construct($host);
    }

    public function render(): string
    {
        $bubbles = '';
        foreach ($this->messages as $m) {
            $bubbles .= new MessageBubble($this->host, $m);
        }

        $poll = $this->safeUrl($this->pollUrl);
        $hx = $poll === null ? [] : Hx::attrs([
            'get' => $poll . (str_contains($poll, '?') ? '&' : '?') . 'after=' . $this->afterId,
            'trigger' => 'every 4s',
            'swap' => 'beforeend',
        ]) + ['data-cp-poll' => ''];

        $log = $this->tag('div', ['id' => 'cp-conversation', 'class' => 'cp-conversation', 'role' => 'log', 'aria-live' => 'off'] + $hx, $bubbles);
        $jump = $this->tag(
            'button',
            ['type' => 'button', 'id' => 'cp-jump', 'class' => 'cp-jump', 'hidden' => ''],
            new Icon($this->host, 'arrow-down') . $this->e($this->host->t('Jump to latest')),
        );

        return $this->tag('div', ['class' => 'cp-thread'], $log . $jump . $this->tag('div', ['class' => 'cp-sr', 'role' => 'status', 'id' => 'cp-announce']));
    }
}
