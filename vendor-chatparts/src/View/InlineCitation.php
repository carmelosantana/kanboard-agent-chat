<?php

declare(strict_types=1);

namespace ChatParts\View;

use ChatParts\Host\HostAdapter;
use ChatParts\Model\Part\SourcePart;

/**
 * A `[n]` marker as a link to its source. A marker with no matching source renders nothing (the
 * model cited something it was not given); a source whose URL is not safe to link renders the
 * number without a link.
 */
final class InlineCitation extends Component
{
    public function __construct(HostAdapter $host, private readonly int $n, private readonly ?SourcePart $source)
    {
        parent::__construct($host);
    }

    public function render(): string
    {
        if ($this->source === null) {
            return '';
        }
        $href = $this->u($this->source->url);

        return $href === ''
            ? '<span class="cp-cite">' . $this->n . '</span>'
            : '<a class="cp-cite" href="' . $href . '">' . $this->n . '</a>';
    }
}
