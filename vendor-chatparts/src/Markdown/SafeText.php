<?php

declare(strict_types=1);

namespace ChatParts\Markdown;

/**
 * Plain text made safe to print, for hosts without a Markdown renderer and for text that must
 * never be parsed as markup: everything is escaped, blank lines make paragraphs, single line
 * breaks become <br>, and bare http(s) URLs become links. No other scheme is ever linked.
 */
final class SafeText
{
    private const URL = '~(https?://[^\s<>"\'`]+)~i';

    public static function render(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return '';
        }

        $paragraphs = [];
        foreach (preg_split('/\n\s*\n/', $text) ?: [] as $para) {
            $lines = array_map(self::linkify(...), explode("\n", trim($para)));
            $paragraphs[] = '<p>' . implode("<br>\n", $lines) . '</p>';
        }

        return implode("\n", $paragraphs);
    }

    private static function linkify(string $line): string
    {
        $out = '';
        foreach (preg_split(self::URL, $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $i => $piece) {
            if ($i % 2 === 0) {
                $out .= self::escape($piece);
                continue;
            }
            [$url, $tail] = self::splitTrailing($piece);
            $href = self::escape($url);
            $out .= "<a href=\"{$href}\" rel=\"nofollow noopener noreferrer\" target=\"_blank\">{$href}</a>" . self::escape($tail);
        }

        return $out;
    }

    /**
     * Sentence punctuation after a URL is not part of it; a closing bracket is kept only when
     * the URL opened one (`https://en.wikipedia.org/wiki/Foo_(bar)`).
     *
     * @return array{string, string}
     */
    private static function splitTrailing(string $url): array
    {
        $tail = '';
        while ($url !== '') {
            $last = substr($url, -1);
            $unbalanced = $last === ')' && substr_count($url, '(') < substr_count($url, ')');
            if (!str_contains('.,;:!?', $last) && !$unbalanced) {
                break;
            }
            $tail = $last . $tail;
            $url = substr($url, 0, -1);
        }

        return [$url, $tail];
    }

    private static function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
