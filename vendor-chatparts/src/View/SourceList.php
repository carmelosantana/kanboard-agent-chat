<?php

declare(strict_types=1);

namespace ChatParts\View;

use ChatParts\Host\HostAdapter;
use ChatParts\Model\Part\SourcePart;

/** The numbered sources row under an answer: one link per source given, nothing when there are none. */
final class SourceList extends Component
{
    /** @param list<SourcePart> $sources */
    public function __construct(HostAdapter $host, private readonly array $sources)
    {
        parent::__construct($host);
    }

    public function render(): string
    {
        if ($this->sources === []) {
            return '';
        }

        $items = '';
        foreach ($this->sources as $s) {
            $inner = $this->tag('span', ['class' => 'cp-source__n'], (string) $s->n) . $this->tag('span', ['class' => 'cp-source__title'], $this->e($s->title));
            $href = $this->u($s->url);
            $body = $href === '' ? $inner : '<a class="cp-source__link" href="' . $href . '">' . $inner . '</a>';
            $items .= $this->tag('li', ['class' => 'cp-source', 'data-kind' => $s->kind], $body);
        }

        return $this->tag('ol', ['class' => 'cp-sources', 'aria-label' => $this->host->t('Sources')], $items);
    }
}
