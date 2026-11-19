<?php

declare(strict_types=1);

namespace ChatParts\View;

use ChatParts\Host\HostAdapter;
use ChatParts\Model\Participant;

/**
 * Who a message is from, at a glance. Kind sets the shape (the stylesheet draws a round person,
 * a rounded-square agent, a dark tile bot); the host picks the initials and tone. Decorative:
 * the name is in the bubble's meta row, so the avatar is hidden from assistive tech.
 */
final class Avatar extends Component
{
    private const KINDS = ['human', 'agent', 'bot', 'system'];

    public function __construct(HostAdapter $host, private readonly Participant $p)
    {
        parent::__construct($host);
    }

    /** The participant's kind, held to the known four so it is safe in a class name; anything else reads as `system`. */
    public static function kind(Participant $p): string
    {
        return in_array($p->kind, self::KINDS, true) ? $p->kind : 'system';
    }

    public function render(): string
    {
        $kind = self::kind($this->p);
        $attrs = ['class' => 'cp-avatar cp-avatar--' . $kind, 'aria-hidden' => 'true'];
        if ($kind === 'bot') {
            return $this->tag('span', $attrs, (string) new Icon($this->host, 'bot'));
        }

        ['initials' => $initials, 'tone' => $tone] = $this->host->avatarFor($this->p);
        if (preg_match('/^#[0-9a-f]{6}$/i', $tone) === 1) {
            $attrs['style'] = '--cp-tone:' . $tone;
        } elseif (preg_match('/^[a-z0-9-]+$/i', $tone) === 1) {
            $attrs['class'] .= ' cp-tone-' . strtolower($tone);
        }

        return $this->tag('span', $attrs, $this->e($initials));
    }
}
