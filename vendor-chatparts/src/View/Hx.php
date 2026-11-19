<?php

declare(strict_types=1);

namespace ChatParts\View;

use InvalidArgumentException;
use JsonException;

/**
 * The one place an htmx attribute name is written. Every component asks here, so the set of
 * htmx behaviours the kit uses is a closed list (anything else is a typo or an injection
 * vector and is refused). Hx only names attributes: URLs come from the host, already built,
 * and values are escaped by Component::tag() on the way out.
 */
final class Hx
{
    /** Allowed key => attribute name. The sse-* names are htmx-ext-sse's own, unprefixed. */
    private const NAMES = [
        'get' => 'hx-get',
        'post' => 'hx-post',
        'target' => 'hx-target',
        'swap' => 'hx-swap',
        'trigger' => 'hx-trigger',
        'ext' => 'hx-ext',
        'sse-connect' => 'sse-connect',
        'sse-swap' => 'sse-swap',
        'sse-close' => 'sse-close',
        'vals' => 'hx-vals',
        'headers' => 'hx-headers',
        'swap-oob' => 'hx-swap-oob',
        'indicator' => 'hx-indicator',
        'disabled-elt' => 'hx-disabled-elt',
    ];

    /** Keys whose value is an array, written as a JSON object (never a `js:` expression). */
    private const JSON = ['vals', 'headers'];

    /**
     * Attribute name => value, ready to merge into the attrs given to Component::tag(). A null
     * value stays null, so tag() drops it.
     *
     * @param array<string, string|array<string, mixed>|null> $hx
     * @return array<string, string|null>
     * @throws InvalidArgumentException for a key not on the list, an array given to a key that
     *   takes a string (or the reverse), or an array that cannot be JSON-encoded
     */
    public static function attrs(array $hx): array
    {
        $out = [];
        foreach ($hx as $k => $v) {
            $name = self::NAMES[$k] ?? throw new InvalidArgumentException("Unknown htmx attribute: {$k}");
            if ($v === null) {
                $out[$name] = null;
            } elseif (in_array($k, self::JSON, true)) {
                if (!is_array($v)) {
                    throw new InvalidArgumentException("Value for {$name} must be an array.");
                }
                try {
                    $out[$name] = json_encode((object) $v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } catch (JsonException $e) {
                    throw new InvalidArgumentException("Value for {$name} cannot be JSON-encoded.", 0, $e);
                }
            } elseif (is_string($v)) {
                $out[$name] = $v;
            } else {
                throw new InvalidArgumentException("Value for {$name} must be a string.");
            }
        }

        return $out;
    }
}
