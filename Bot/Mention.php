<?php

namespace Kanboard\Plugin\AgentChat\Bot;

/**
 * Whether a message asks the project bot: a standalone `@bot` (any case), not part of a longer
 * name or address (`@bots`, `@bot-ops`, `mail@bot.com`) and not inside a code span or fence.
 */
final class Mention
{
    public static function ofBot(string $text): bool
    {
        $prose = (string) preg_replace('/```.*?(```|\z)|`[^`\n]*`/s', ' ', $text);
        return preg_match('/(?<![\w@#.\/-])@bot(?![\w-])/i', $prose) === 1;
    }
}
