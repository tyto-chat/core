<?php

declare(strict_types=1);

namespace App\Utils;

final class MarkdownSanitizer
{
    public static function sanitize(string $text): string
    {
        $pattern = '/(```[\s\S]*?```|~~~[\s\S]*?~~~|`+[^`]*`+)/';

        $parts = preg_split($pattern, $text, flags: PREG_SPLIT_DELIM_CAPTURE);

        if (false === $parts) {
            return $text;
        }

        $result = '';
        foreach ($parts as $i => $part) {
            if (1 === $i % 2) {
                $result .= $part;
                continue;
            }

            $result .= self::escapeHtmlTags($part);
        }

        return $result;
    }

    private static function escapeHtmlTags(string $text): string
    {
        return preg_replace_callback(
            '/<\/?[a-zA-Z][^>]*>/',
            static function (array $m): string {
                // Autolink check must anchor the scheme at the tag start — a tag merely containing a URL (<img onerror=fetch("https://…")>) must still be escaped
                if (1 === preg_match('/^<[a-z][a-z0-9+\-.]*:\/\//i', $m[0]) || str_starts_with(strtolower($m[0]), '<mailto:')) {
                    return $m[0];
                }

                return '&lt;'.substr($m[0], 1, -1).'&gt;';
            },
            $text
        ) ?? $text;
    }
}
