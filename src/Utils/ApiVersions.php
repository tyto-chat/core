<?php

declare(strict_types=1);

namespace App\Utils;

final class ApiVersions
{
    public const string CANONICAL = 'v1';

    /** @var list<string> ascending */
    public const array VERSIONS = ['v1'];

    /** @var array<string, list<string>> */
    public const array FEATURES = [
        'auth' => ['v1'],
        'messaging' => ['v1'],
        'threads' => ['v1'],
        'dms' => ['v1'],
        'search' => ['v1'],
        'reactions' => ['v1'],
        'voice' => ['v1'],
        'presence' => ['v1'],
        'notifications' => ['v1'],
        'webPush' => ['v1'],
        'moderation' => ['v1'],
        'webhooks' => ['v1'],
        'embeds' => ['v1'],
        'admin' => ['v1'],
    ];

    public static function routeRequirement(): string
    {
        return implode('|', self::VERSIONS);
    }
}
