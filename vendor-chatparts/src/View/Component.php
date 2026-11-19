<?php

declare(strict_types=1);

namespace ChatParts\View;

use ChatParts\Host\HostAdapter;

/**
 * A server-rendered fragment. A component is a value that knows how to render itself; casting
 * one to string renders it, so a parent can drop a child straight into its own markup. The
 * one-letter helpers are the escaping vocabulary: a template written with them reads as HTML,
 * and the short names keep the escape call cheaper to write than to skip.
 */
abstract class Component
{
    /** Elements that take no closing tag. */
    private const VOID = ['area', 'br', 'col', 'hr', 'img', 'input', 'source', 'wbr'];

    /** Schemes u() lets through; a URL with no scheme (relative) is always allowed. */
    private const URL_SCHEMES = ['http', 'https', 'mailto'];

    public function __construct(protected readonly HostAdapter $host)
    {
    }

    abstract public function render(): string;

    public function __toString(): string
    {
        return $this->render();
    }

    /** Element content, escaped by the host. */
    protected function e(string $s): string
    {
        return $this->host->escape($s);
    }

    /** An attribute value, quotes included. */
    protected function a(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * A URL for an href or src: relative, http(s) or mailto, attribute-escaped. Anything with
     * another scheme (javascript:, data:, vbscript:) comes back as an empty string.
     */
    protected function u(string $s): string
    {
        $url = $this->safeUrl($s);

        return $url === null ? '' : $this->a($url);
    }

    /**
     * The same check as u(), unescaped, for a URL headed into tag() (which escapes it): the
     * cleaned URL, or null for an empty or refused one, so tag() drops the attribute.
     */
    protected function safeUrl(string $s): ?string
    {
        $s = (string) preg_replace('/[\x00-\x1F\x7F]/', '', trim($s));
        if ($s === '' || (preg_match('/^([a-z][a-z0-9+.-]*):/i', $s, $m) === 1 && !in_array(strtolower($m[1]), self::URL_SCHEMES, true))) {
            return null;
        }

        return $s;
    }

    /**
     * An element with attribute-escaped values. A null value drops the attribute, so a
     * conditional attribute (`'disabled' => $busy ? '' : null`) needs no branching at the
     * call site. `$inner` is used as given: the caller has already escaped it, or it is
     * another component's output. Only attribute *values* are escaped: `$name` and the
     * attribute names are written raw, so they must be literals in the calling code (or come
     * from Hx::attrs()), never data.
     *
     * @param array<string, string|null> $attrs
     */
    protected function tag(string $name, array $attrs, string $inner = ''): string
    {
        $a = '';
        foreach ($attrs as $k => $v) {
            if ($v === null) {
                continue;
            }
            $a .= sprintf(' %s="%s"', $k, $this->a($v));
        }

        return in_array($name, self::VOID, true) ? "<{$name}{$a}>" : "<{$name}{$a}>{$inner}</{$name}>";
    }
}
