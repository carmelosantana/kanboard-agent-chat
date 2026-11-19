<?php

namespace Kanboard\Plugin\AgentChat\Helper;

use ChatParts\Host\HostAdapter;
use ChatParts\Model\Participant;
use InvalidArgumentException;
use Kanboard\Core\Base;
use Kanboard\Core\Markdown;

/**
 * chatparts' view of Kanboard: routes, CSRF, translation, markdown and asset URLs.
 *
 * Escaping is the kit's job: t() hands arguments to Kanboard's translator unescaped (e(), not
 * t(), which would escape them a second time), and markdown() returns HTML that is already
 * safe, so the kit prints it as given.
 */
class KanboardHost extends Base implements HostAdapter
{
    /** Route name => [controller, action]; all AgentChat's. */
    private const ROUTES = [
        'chat' => ['RoomController', 'show'],
        'poll' => ['RoomController', 'poll'],
        'send' => ['RoomController', 'send'],
    ];

    private const TONES = ['blue', 'green', 'orange', 'pink', 'purple', 'red', 'slate', 'teal'];

    private const ASSET_DIR = 'plugins/AgentChat/vendor-chatparts/assets/';

    public function url(string $route, array $params = []): string
    {
        [$controller, $action] = self::ROUTES[$route] ?? throw new InvalidArgumentException("Unknown AgentChat route: $route");

        return $this->helper->url->to($controller, $action, $params + ['plugin' => 'AgentChat']);
    }

    public function csrfField(): string
    {
        return $this->helper->form->csrf();
    }

    public function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Kanboard's Markdown parser (Parsedown with Kanboard's `#12` task and `@user` links) in
     * safe mode with raw HTML escaped, then two allow-lists over its output:
     *  - links: http(s), mailto, a path from the site root, `?query` or `#fragment`; any other
     *    href (javascript:, data:, ftp:, an entity-hidden scheme...) becomes its plain text;
     *  - images: a path from the site root, or http(s) on this Kanboard's own host and port;
     *    anything else is dropped.
     * Any URL with a backslash, whitespace or control character fails both, since browsers
     * read `\` as `/` (`/\evil.example` is another host).
     */
    public function markdown(string $text): string
    {
        $parser = new Markdown($this->container, false);
        $parser->setMarkupEscaped(true);
        $parser->setBreaksEnabled(true);
        $parser->setSafeMode(true);
        $html = $parser->text($text);

        $html = (string) preg_replace_callback('~<a\b([^>]*)>(.*?)</a>~s', function (array $m): string {
            return $this->isSafeHref($this->attr($m[1], 'href')) ? $m[0] : $m[2];
        }, $html);

        return (string) preg_replace_callback('~<img\b([^>]*)>~i', function (array $m): string {
            return $this->isSameOriginImage($this->attr($m[1], 'src')) ? $m[0] : '';
        }, $html);
    }

    public function avatarFor(Participant $p): array
    {
        $words = preg_split('/[\s._-]+/u', trim($p->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = implode('', array_map(static fn (string $w): string => mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8'), array_slice($words, 0, 2)));

        return [
            'initials' => $initials === '' ? '?' : $initials,
            'tone' => self::TONES[crc32($p->kind.':'.($p->id ?? $p->name)) % count(self::TONES)],
        ];
    }

    public function t(string $s, mixed ...$args): string
    {
        return e($s, ...$args);
    }

    public function assetUrl(string $file): string
    {
        return $this->helper->url->dir().self::ASSET_DIR.$file;
    }

    /** An attribute's value from Parsedown's output, entities decoded once (as the browser does); null when absent. */
    private function attr(string $attrs, string $name): ?string
    {
        return preg_match('~\s'.$name.'="([^"]*)"~i', $attrs, $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
    }

    private function isSafeHref(?string $url): bool
    {
        return $url !== null && ! $this->hasUnsafeChars($url)
            && preg_match('~^(https?://[^/?#@]|mailto:[^/]|/(?![/])|[?#])~i', $url) === 1;
    }

    private function isSameOriginImage(?string $url): bool
    {
        if ($url === null || $this->hasUnsafeChars($url)) {
            return false;
        }
        if (preg_match('~^/(?!/)~', $url) === 1) {
            return true;
        }
        // Absolute: the authority must be a bare host[:port] (no userinfo) on this Kanboard.
        if (preg_match('~^(https?)://([a-z0-9.-]+)(?::(\d{1,5}))?(?:[/?#]|$)~i', $url, $m) !== 1) {
            return false;
        }
        $parts = ['scheme' => $m[1], 'host' => $m[2]] + (isset($m[3]) && $m[3] !== '' ? ['port' => (int) $m[3]] : []);

        return in_array($this->origin($parts), $this->ownOrigins(), true);
    }

    /** Backslashes, whitespace and control characters: browsers strip or reinterpret them. */
    private function hasUnsafeChars(string $url): bool
    {
        return $url === '' || preg_match('~[\\\\\x00-\x20\x7F]~', $url) === 1;
    }

    /** @return list<string> host:port pairs this Kanboard answers on */
    private function ownOrigins(): array
    {
        $origins = [];
        $base = parse_url($this->helper->url->base());
        if (is_array($base) && isset($base['host'])) {
            $origins[] = $this->origin($base);
        }
        $host = (string) $this->request->getServerVariable('HTTP_HOST');
        if ($host !== '') {
            $parsed = parse_url('//'.$host);
            if (is_array($parsed) && isset($parsed['host'])) {
                $origins[] = $this->origin($parsed + ['scheme' => $this->request->isHTTPS() ? 'https' : 'http']);
            }
        }
        return $origins;
    }

    private function origin(array $parts): string
    {
        $scheme = strtolower($parts['scheme'] ?? 'http');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return strtolower($parts['host']).':'.$port;
    }
}
