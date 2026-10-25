<?php

declare(strict_types=1);

namespace App\Utils;

final class CacheTags
{
    public static function userContext(int $userId): string
    {
        return 'ctx-u'.$userId;
    }

    public static function communityContext(int $communityId): string
    {
        return 'ctx-c'.$communityId;
    }

    public static function communityPages(int $communityId): string
    {
        return 'pages-k'.$communityId;
    }

    public static function author(int $userId): string
    {
        return 'author-'.$userId;
    }

    private function __construct()
    {
    }
}
