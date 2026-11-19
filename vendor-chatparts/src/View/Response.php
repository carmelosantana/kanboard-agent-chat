<?php

declare(strict_types=1);

namespace ChatParts\View;

use ChatParts\Host\HostAdapter;
use ChatParts\Model\Part\SourcePart;
use ChatParts\Model\Part\TextPart;

/**
 * A text part. While streaming it is the raw text, escaped, with a caret (the stream appends
 * escaped deltas to the same container). Once done it is the host's markdown, with each `[n]`
 * marker in the text (not in a tag, `<code>` or `<pre>`) turned into an InlineCitation.
 */
final class Response extends Component
{
    /** @var array<int, SourcePart> by citation number */
    private readonly array $sources;

    /** @param list<SourcePart> $sources the message's sources, for citation links */
    public function __construct(HostAdapter $host, private readonly TextPart $part, private readonly bool $streaming, array $sources = [])
    {
        parent::__construct($host);
        $byN = [];
        foreach ($sources as $s) {
            $byN[$s->n] ??= $s;
        }
        $this->sources = $byN;
    }

    public function render(): string
    {
        $id = 'cp-text-' . $this->part->id;
        if ($this->streaming) {
            return $this->tag('div', ['id' => $id, 'class' => 'cp-response cp-caret'], htmlspecialchars($this->part->text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        return $this->tag('div', ['id' => $id, 'class' => 'cp-response'], $this->cite($this->host->markdown($this->part->text)));
    }

    private function cite(string $html): string
    {
        $out = '';
        $verbatim = 0;
        foreach (preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $i => $piece) {
            if ($i % 2 === 1) {
                if (preg_match('~^<(/?)(code|pre)\b~i', $piece, $m) === 1) {
                    $verbatim = max(0, $verbatim + ($m[1] === '/' ? -1 : 1));
                }
                $out .= $piece;
                continue;
            }
            $out .= $verbatim > 0 ? $piece : (string) preg_replace_callback(
                '/\[([1-9][0-9]?)\]/',
                fn (array $m): string => (string) new InlineCitation($this->host, (int) $m[1], $this->sources[(int) $m[1]] ?? null),
                $piece,
            );
        }

        return $out;
    }
}
