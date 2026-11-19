<?php

declare(strict_types=1);

namespace ChatParts\View;

use ChatParts\Host\HostAdapter;
use InvalidArgumentException;

/**
 * How much of the model's context window the next request would use: a small ring with the
 * whole-percent figure (the content of the composer's context button), and the breakdown rows,
 * sent out of band into the composer's `#cp-context-pop` so the popover's open state survives
 * each refresh. Segments with no tokens are left out of the rows; a total past the window
 * draws a full ring.
 */
final class ContextMeter extends Component
{
    /** Ring radius, and its circumference rounded the way the dash is. */
    private const R = 7;
    private const C = 44.0;

    /** @var list<array{key: string, label: string, tokens: int}> */
    private readonly array $segments;

    /**
     * @param list<array{key: string, label: string, tokens: int}> $segments in display order;
     *   labels are shown as given (the host translates them)
     * @throws InvalidArgumentException for a window that is not positive or a malformed segment
     */
    public function __construct(HostAdapter $host, array $segments, private readonly int $window)
    {
        parent::__construct($host);
        if ($window <= 0) {
            throw new InvalidArgumentException('Context window must be greater than zero.');
        }
        $clean = [];
        foreach ($segments as $i => $s) {
            if (!is_array($s) || !is_string($s['key'] ?? null) || !is_string($s['label'] ?? null) || !is_int($s['tokens'] ?? null) || $s['tokens'] < 0) {
                throw new InvalidArgumentException("Context segment {$i} needs a string key and label and a non-negative int tokens.");
            }
            $clean[] = ['key' => $s['key'], 'label' => $s['label'], 'tokens' => $s['tokens']];
        }
        $this->segments = $clean;
    }

    public function render(): string
    {
        $used = array_sum(array_column($this->segments, 'tokens'));
        $share = min(1.0, $used / $this->window);
        $pct = (int) round($share * 100);
        $dash = sprintf('%.1f %.1f', round($share * self::C, 1), self::C);

        $svg = '<svg class="cp-ring__svg" viewBox="0 0 18 18" aria-hidden="true" focusable="false">'
            . '<circle class="cp-ring__track" cx="9" cy="9" r="' . self::R . '"/>'
            . $this->tag('circle', ['class' => 'cp-ring__bar', 'cx' => '9', 'cy' => '9', 'r' => (string) self::R, 'stroke-dasharray' => $dash])
            . '</svg>';
        $ring = $this->tag(
            'span',
            ['class' => 'cp-ring', 'data-pct' => (string) $pct],
            $svg
            . $this->tag('span', ['class' => 'cp-ring__pct', 'aria-hidden' => 'true'], $pct . '%')
            . $this->tag('span', ['class' => 'cp-sr'], $this->e($this->host->t('%d%% of context used', $pct))),
        );

        $rows = '';
        foreach ($this->segments as $s) {
            if ($s['tokens'] === 0) {
                continue;
            }
            $rows .= $this->tag(
                'div',
                ['class' => 'cp-context__row', 'data-key' => preg_match('/^[a-z0-9_-]+$/i', $s['key']) === 1 ? $s['key'] : null],
                $this->tag('span', ['class' => 'cp-context__label'], $this->e($s['label']))
                . $this->tag('span', ['class' => 'cp-context__tokens'], number_format($s['tokens'])),
            );
        }
        $total = $this->tag('p', ['class' => 'cp-context__total'], $this->e($this->host->t('%s of %s tokens', number_format($used), number_format($this->window))));

        return $ring . $this->tag('div', ['id' => 'cp-context-pop'] + Hx::attrs(['swap-oob' => 'innerHTML']), $rows . $total);
    }
}
