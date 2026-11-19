<?php

declare(strict_types=1);

namespace ChatParts\View;

use ChatParts\Host\HostAdapter;
use InvalidArgumentException;
use LogicException;

/**
 * A Lucide icon from the sprite at assets/icons.svg. Decorative: hidden from assistive tech,
 * so the control around it carries the accessible name. The names allowed are the sprite's
 * own <symbol> ids, so a host that vendors only src/ and assets/ has everything it needs
 * (scripts/icons.lock is build-time only; a test keeps the two in step).
 */
final class Icon extends Component
{
    private const SPRITE = __DIR__ . '/../../assets/icons.svg';

    /** @var list<string>|null */
    private static ?array $names = null;

    /** @throws InvalidArgumentException for a name that is not in the sprite */
    public function __construct(HostAdapter $host, private readonly string $name, private readonly string $class = 'cp-icon')
    {
        parent::__construct($host);
        if (!in_array($name, self::names(), true)) {
            throw new InvalidArgumentException("Unknown icon: {$name}");
        }
    }

    /**
     * @return list<string> the icon names in the sprite, in sprite order; read once per process
     * @throws LogicException when the sprite is missing, unreadable or holds no symbols
     */
    public static function names(): array
    {
        if (self::$names === null) {
            $svg = is_file(self::SPRITE) && is_readable(self::SPRITE) ? file_get_contents(self::SPRITE) : false;
            if ($svg === false || preg_match_all('/<symbol id="([a-z0-9-]+)"/', $svg, $m) === 0) {
                throw new LogicException('Icon sprite not readable: ' . self::SPRITE . ' (ship assets/icons.svg with src/).');
            }
            self::$names = $m[1];
        }

        return self::$names;
    }

    public function render(): string
    {
        $href = $this->a($this->host->assetUrl('icons.svg') . '#' . $this->name);

        return $this->tag('svg', ['class' => $this->class, 'aria-hidden' => 'true', 'focusable' => 'false'], "<use href=\"{$href}\"/>");
    }
}
