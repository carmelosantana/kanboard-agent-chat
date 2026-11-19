<?php

declare(strict_types=1);

namespace ChatParts\Stream;

use ChatParts\View\Hx;
use InvalidArgumentException;

/**
 * The event names a chat stream sends, and the helper that marks a fragment for an
 * out-of-band swap. The client listens with `sse-swap="<name>"` and closes on `done`
 * (`sse-close="done"`), so these names are the contract between SseWriter and the markup.
 */
final class StreamEvents
{
    /** Appended response text. */
    public const DELTA = 'delta';
    /** Appended reasoning text. */
    public const REASONING = 'reasoning';
    /** Tool, source or file parts, usually out-of-band fragments. */
    public const PARTS = 'parts';
    /** The finished message, replacing the streamed draft. */
    public const FINAL = 'final';
    /** End of stream: the client closes and does not reconnect. */
    public const DONE = 'done';

    /** Leading whitespace and comments, then the root start tag (name, attributes, end). */
    private const ROOT = '/^((?:\s|<!--.*?-->)*)<([a-zA-Z][a-zA-Z0-9-]*)'
        . '((?:\s+[^\s"\'>\/=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?)*)(\s*\/?>)/s';

    /** One attribute inside the root start tag, with its leading whitespace. */
    private const ATTR = '/\s+([^\s"\'>\/=]+)(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?/';

    /**
     * The fragment with `hx-swap-oob="$mode"` on its first element only. An existing
     * hx-swap-oob (or data-hx-swap-oob) on that element is replaced; descendants are left
     * alone. Leading whitespace and comments before the root are kept.
     *
     * @throws InvalidArgumentException when the fragment does not start with an element
     */
    public static function oob(string $html, string $mode = 'true'): string
    {
        if (preg_match(self::ROOT, $html, $m) !== 1) {
            throw new InvalidArgumentException('An out-of-band fragment must start with an element.');
        }
        [$whole, $lead, $tag, $attrs, $end] = $m;

        $name = array_key_first(Hx::attrs(['swap-oob' => $mode]));
        $kept = '';
        preg_match_all(self::ATTR, $attrs, $found, PREG_SET_ORDER);
        foreach ($found as [$attr, $attrName]) {
            $lower = strtolower($attrName);
            if ($lower !== $name && $lower !== "data-{$name}") {
                $kept .= $attr;
            }
        }
        $value = htmlspecialchars($mode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return "{$lead}<{$tag} {$name}=\"{$value}\"{$kept}{$end}" . substr($html, strlen($whole));
    }
}
