<?php

declare(strict_types=1);

namespace App\Enum\Channel;

use App\Enum\BackedEnumValuesTrait;

enum ChannelRole: string
{
    use BackedEnumValuesTrait;

    case Member = 'member';
    case Moderator = 'moderator';

    public static function highest(?self $a, ?self $b): ?self
    {
        if (self::Moderator === $a || self::Moderator === $b) {
            return self::Moderator;
        }
        if (self::Member === $a || self::Member === $b) {
            return self::Member;
        }

        return null;
    }
}
