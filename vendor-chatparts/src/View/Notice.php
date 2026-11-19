<?php

declare(strict_types=1);

namespace ChatParts\View;

use ChatParts\Host\HostAdapter;

/**
 * A one-line info, warning or error message. The level is held to those three so the class is
 * never built from data; anything else reads as `info`. An error is announced at once
 * (`role="alert"`), the others politely.
 */
final class Notice extends Component
{
    private const ICONS = ['info' => 'info', 'warning' => 'triangle-exclamation-point', 'error' => 'circle-x'];

    public function __construct(HostAdapter $host, private readonly string $level, private readonly string $text)
    {
        parent::__construct($host);
    }

    public function render(): string
    {
        $level = isset(self::ICONS[$this->level]) ? $this->level : 'info';

        return $this->tag(
            'div',
            ['class' => 'cp-notice cp-notice--' . $level, 'role' => $level === 'error' ? 'alert' : 'status'],
            new Icon($this->host, self::ICONS[$level]) . $this->tag('p', [], $this->e($this->text)),
        );
    }
}
