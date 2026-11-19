<?php

namespace Kanboard\Plugin\AgentChat\Bot;

use ChatParts\Model\Part\SourcePart;
use InvalidArgumentException;

/**
 * The numbered sources of one bot run: every item a tool returns is registered here and the
 * bot cites it as `[n]`. Numbers start at 1 and stay the same for a (kind, ref) all run long;
 * the first registration's title and URL win. The kit renders `[1]`–`[99]`, so past 99
 * entries a new item gets no number (null) while known ones keep theirs.
 *
 * URLs are same-origin paths from the site root (`/…`, not `//…`), the shape the tools build
 * with Kanboard's url helper; anything else is refused.
 */
final class SourceRegistry
{
    public const MAX = 99;
    private const KINDS = ['task', 'comment', 'knowledge'];

    /** @var array<int, SourcePart> n => part */
    private array $parts = [];

    /** @var array<string, int> "kind:ref" => n */
    private array $numbers = [];

    /** The item's citation number, or null once the registry is full. */
    public function register(string $kind, string $ref, string $title, string $url): ?int
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown source kind: $kind");
        }
        if (preg_match('~^/(?![/\\\\])[^\\\\\x00-\x20\x7F]*$~', $url) !== 1) {
            throw new InvalidArgumentException('A source URL must be a path from the site root.');
        }

        $key = $kind.':'.$ref;
        if (isset($this->numbers[$key])) {
            return $this->numbers[$key];
        }
        if (count($this->parts) >= self::MAX) {
            return null;
        }

        $n = count($this->parts) + 1;
        $this->numbers[$key] = $n;
        $this->parts[$n] = new SourcePart('s'.bin2hex(random_bytes(8)), $n, $title, $url, $kind, $ref);

        return $n;
    }

    /** @return list<SourcePart> in number order */
    public function all(): array
    {
        return array_values($this->parts);
    }

    public function resolve(int $n): ?SourcePart
    {
        return $this->parts[$n] ?? null;
    }
}
