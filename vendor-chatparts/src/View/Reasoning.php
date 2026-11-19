<?php

declare(strict_types=1);

namespace ChatParts\View;

use ChatParts\Host\HostAdapter;
use ChatParts\Model\Part\ReasoningPart;

/** A reasoning part: a `<details>` that is open while it streams and collapsed once done. */
final class Reasoning extends Component
{
    public function __construct(HostAdapter $host, private readonly ReasoningPart $part, private readonly bool $streaming = false)
    {
        parent::__construct($host);
    }

    public function render(): string
    {
        $ms = $this->part->durationMs;
        $label = match (true) {
            $this->streaming => $this->host->t('Thinking…'),
            $ms !== null => $this->host->t('Thought for %d s', max(1, (int) round($ms / 1000))),
            default => $this->host->t('Thoughts'),
        };
        $summary = $this->tag('summary', ['class' => 'cp-reasoning__summary'], new Icon($this->host, 'brain') . $this->e($label));

        return $this->tag(
            'details',
            ['id' => 'cp-reasoning-' . $this->part->id, 'class' => 'cp-reasoning', 'open' => $this->streaming ? '' : null],
            $summary . $this->tag('div', ['class' => 'cp-reasoning__text'], $this->e($this->part->text)),
        );
    }
}
