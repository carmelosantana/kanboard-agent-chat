<?php

declare(strict_types=1);

namespace ChatParts\View;

use ChatParts\Host\HostAdapter;
use ChatParts\Model\Part\SourcePart;
use ChatParts\Model\Part\ToolPart;
use ChatParts\Model\ToolState;

/**
 * Every tool call of one message in a closed `<details>`: the summary counts sources and calls,
 * the list has one row per call with its state in `data-state`.
 */
final class ToolStrip extends Component
{
    /**
     * @param list<ToolPart>   $tools
     * @param list<SourcePart> $sources the message's sources; only counted
     */
    public function __construct(HostAdapter $host, private readonly string $messageId, private readonly array $tools, private readonly array $sources = [])
    {
        parent::__construct($host);
    }

    public function render(): string
    {
        $n = count($this->sources);
        $m = count($this->tools);
        $calls = $m === 1 ? $this->host->t('%d tool', $m) : $this->host->t('%d tools', $m);
        $summary = match ($n) {
            0 => $this->host->t('Used %s', $calls),
            1 => $this->host->t('Searched %d source · %s', $n, $calls),
            default => $this->host->t('Searched %d sources · %s', $n, $calls),
        };

        $rows = '';
        foreach ($this->tools as $tool) {
            $detail = $tool->error ?? $tool->outputSummary;
            $rows .= $this->tag(
                'li',
                ['class' => 'cp-tool', 'data-state' => $tool->state->value],
                new Icon($this->host, self::icon($tool->state))
                . $this->tag('span', ['class' => 'cp-tool__name'], $this->e($tool->name))
                . ($detail === null ? '' : $this->tag('span', ['class' => 'cp-tool__detail'], $this->e($detail))),
            );
        }

        return $this->tag(
            'details',
            ['id' => 'cp-tools-' . $this->messageId, 'class' => 'cp-tools'],
            $this->tag('summary', ['class' => 'cp-tools__summary'], new Icon($this->host, 'wrench') . $this->e($summary))
            . $this->tag('ul', ['class' => 'cp-tools__list'], $rows),
        );
    }

    private static function icon(ToolState $state): string
    {
        return match ($state) {
            ToolState::Pending, ToolState::Running => 'loader-circle',
            ToolState::Done => 'circle-check',
            ToolState::Error => 'circle-x',
            ToolState::Denied => 'lock',
        };
    }
}
