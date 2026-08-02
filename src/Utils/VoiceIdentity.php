<?php

declare(strict_types=1);

namespace App\Utils;

use App\Entity\Channel;
use App\Entity\User;

/** Canonical LiveKit identity/room naming — the only place the string formats live. */
final class VoiceIdentity
{
    private const string USER_PREFIX = 'user-';
    private const string ROOM_PREFIX = 'channel-';

    public static function user(User $user): string
    {
        return self::USER_PREFIX.$user->getId();
    }

    public static function room(Channel $channel): string
    {
        return self::ROOM_PREFIX.$channel->getId();
    }

    public static function userIdFrom(string $identity): ?int
    {
        return str_starts_with($identity, self::USER_PREFIX)
            ? (int) substr($identity, \strlen(self::USER_PREFIX))
            : null;
    }

    public static function channelIdFrom(string $roomName): ?int
    {
        return str_starts_with($roomName, self::ROOM_PREFIX)
            ? (int) substr($roomName, \strlen(self::ROOM_PREFIX))
            : null;
    }
}
