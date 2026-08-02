<?php

declare(strict_types=1);

namespace App\Utils;

final class MentionExtractor
{
    /**
     * @return int[]
     */
    public static function extractUserIds(string $text): array
    {
        $stripped = preg_replace('/```[\s\S]*?```|~~~[\s\S]*?~~~|`+[^`]*`+/', '', $text) ?? $text;

        preg_match_all('/\[@[^\]]*\]\(user:(\d+)\)/', $stripped, $matches);

        return array_values(array_unique(array_map('intval', $matches[1])));
    }

    /**
     * @return list<string>
     */
    public static function extractBroadcasts(string $text): array
    {
        $stripped = preg_replace('/```[\s\S]*?```|~~~[\s\S]*?~~~|`+[^`]*`+/', '', $text) ?? $text;

        preg_match_all('/\[@[^\]]*\]\(broadcast:(channel|here)\)/', $stripped, $matches);

        return array_values(array_unique($matches[1]));
    }
}
