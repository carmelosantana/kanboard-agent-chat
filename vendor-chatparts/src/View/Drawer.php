<?php

declare(strict_types=1);

namespace ChatParts\View;

use ChatParts\Host\HostAdapter;

/**
 * A right-side panel shell (the task page's "Ask the bot"): a titled header with a close button
 * and a body the caller fills. Opening, closing and focus are chatparts.js's job.
 */
final class Drawer extends Component
{
    /** @param string $body ready HTML: already escaped, or other components' output */
    public function __construct(
        HostAdapter $host,
        private readonly string $title,
        private readonly string $subtitle,
        private readonly string $body,
    ) {
        parent::__construct($host);
    }

    public function render(): string
    {
        $close = $this->tag(
            'button',
            ['type' => 'button', 'class' => 'cp-drawer__close', 'data-action' => 'close', 'aria-label' => $this->host->t('Close')],
            (string) new Icon($this->host, 'x'),
        );
        $header = $this->tag(
            'header',
            ['class' => 'cp-drawer__header'],
            $this->tag('h2', ['id' => 'cp-drawer-title', 'class' => 'cp-drawer__title'], $this->e($this->title))
            . ($this->subtitle === '' ? '' : $this->tag('p', ['class' => 'cp-drawer__subtitle'], $this->e($this->subtitle)))
            . $close,
        );

        return $this->tag('aside', ['id' => 'cp-drawer', 'class' => 'cp-drawer', 'aria-labelledby' => 'cp-drawer-title'], $header . $this->tag('div', ['class' => 'cp-drawer__body'], $this->body));
    }
}
