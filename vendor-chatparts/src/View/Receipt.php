<?php

declare(strict_types=1);

namespace ChatParts\View;

use ChatParts\Host\HostAdapter;
use ChatParts\Model\FinishReason;

/**
 * The muted line under a reply: `project-bot · qwen3 · 2 tools · answered for Ada (Manager)`, or,
 * when the run ended other than normally, the stop reason in words. A part that is missing or
 * zero is left out; a line with nothing to say renders nothing.
 *
 * A stopped run names who stopped it (`stopped_by`): "stopped by you" when the host's viewer is
 * the asker (`asked_by.id`), "stopped by Ada" for everyone else, plain "stopped" with no name.
 */
final class Receipt extends Component
{
    /**
     * @param array<string, mixed> $metadata the message metadata (`model`, `tool_count`,
     *   `finish_reason`, `asked_by` {id, name, role}, `stopped_by`) plus `bot`, the replying
     *   participant's name
     */
    public function __construct(HostAdapter $host, private readonly array $metadata)
    {
        parent::__construct($host);
    }

    public function render(): string
    {
        $reason = is_string($this->metadata['finish_reason'] ?? null) ? FinishReason::tryFrom($this->metadata['finish_reason']) : null;
        if ($reason === FinishReason::Stopped) {
            return $this->line([$this->stopped()]);
        }
        if ($reason !== null && $reason->receiptText() !== '') {
            return $this->line([$this->host->t($reason->receiptText())]);
        }

        $parts = [];
        foreach (['bot', 'model'] as $key) {
            if (is_string($this->metadata[$key] ?? null) && $this->metadata[$key] !== '') {
                $parts[] = $this->metadata[$key];
            }
        }
        $tools = $this->metadata['tool_count'] ?? 0;
        if (is_int($tools) && $tools > 0) {
            $parts[] = $tools === 1 ? $this->host->t('%d tool', $tools) : $this->host->t('%d tools', $tools);
        }
        $asker = $this->metadata['asked_by'] ?? null;
        $name = is_array($asker) && is_string($asker['name'] ?? null) ? $asker['name'] : '';
        if ($name !== '') {
            $role = is_string($asker['role'] ?? null) ? $asker['role'] : '';
            $parts[] = $role === '' ? $this->host->t('answered for %s', $name) : $this->host->t('answered for %s (%s)', $name, $role);
        }

        return $this->line($parts);
    }

    /** Who stopped the run, in words; the viewer is "you" only when they asked. */
    private function stopped(): string
    {
        $name = $this->metadata['stopped_by'] ?? null;
        if (!is_string($name) || $name === '') {
            return $this->host->t(FinishReason::Stopped->receiptText());
        }
        $asker = $this->metadata['asked_by'] ?? null;
        $askerId = is_array($asker) && is_string($asker['id'] ?? null) ? $asker['id'] : null;
        $viewer = $this->host->viewerId();

        return $askerId !== null && $askerId === $viewer ? $this->host->t('stopped by you') : $this->host->t('stopped by %s', $name);
    }

    /** @param list<string> $parts */
    private function line(array $parts): string
    {
        return $parts === [] ? '' : $this->tag('footer', ['class' => 'cp-receipt'], $this->e(implode(' · ', $parts)));
    }
}
