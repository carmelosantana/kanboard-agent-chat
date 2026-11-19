<?php

declare(strict_types=1);

namespace ChatParts\Host;

use ChatParts\Model\Participant;

/**
 * Everything chatparts needs from the app it runs in. The kit never names a framework: a host
 * (an app plugin, a test double) implements this once and hands it to every
 * component.
 */
interface HostAdapter
{
    /** An app URL for a named route; the host decides the shape and encodes the params. */
    public function url(string $route, array $params = []): string;

    /** The hidden form field (or fields) the host's CSRF check expects, as ready HTML. */
    public function csrfField(): string;

    /** Text escaped for HTML element content. */
    public function escape(string $s): string;

    /** Markdown rendered to HTML that the host has already made safe to print. */
    public function markdown(string $text): string;

    /** @return array{initials: string, tone: string} what an avatar shows for this participant */
    public function avatarFor(Participant $p): array;

    /** A translated string; extra args are sprintf-style placeholders. */
    public function t(string $s, mixed ...$args): string;

    /** The public URL of a file in chatparts' assets/ directory (for example 'icons.svg'). */
    public function assetUrl(string $file): string;

    /** The participant id of whoever the page is drawn for (as in `Participant::$id`); null when unknown. */
    public function viewerId(): ?string;
}
