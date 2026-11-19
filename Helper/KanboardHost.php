<?php

namespace Kanboard\Plugin\AgentChat\Helper;

use ChatParts\Host\HostAdapter;
use ChatParts\Model\Participant;
use InvalidArgumentException;
use Kanboard\Core\Base;

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
     * Kanboard's markdown (Parsedown, raw HTML escaped, `#12` linked to the task), then every
     * <img> whose src is not same-origin is dropped: a relative URL, or an absolute one whose
     * host and port are this request's or the configured application URL's.
     */
    public function markdown(string $text): string
    {
        $html = $this->helper->text->markdown($text);

        return (string) preg_replace_callback('/<img\b[^>]*>/i', function (array $m): string {
            return preg_match('/\ssrc="([^"]*)"/i', $m[0], $src) === 1 && $this->isSameOrigin(html_entity_decode($src[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                ? $m[0]
                : '';
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

    private function isSameOrigin(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }
        if (! isset($parts['scheme']) && ! isset($parts['host'])) {
            return ! str_starts_with($url, '//') && ! str_starts_with($url, '\\');
        }
        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ! isset($parts['host'])) {
            return false;
        }

        return in_array($this->origin($parts), $this->ownOrigins(), true);
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
