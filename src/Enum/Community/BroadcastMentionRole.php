<?php

declare(strict_types=1);

namespace App\Enum\Community;

use App\Enum\BackedEnumValuesTrait;

enum BroadcastMentionRole: string
{
    use BackedEnumValuesTrait;

    case Member = 'member';
    case Moderator = 'moderator';
    case Admin = 'admin';

    public function rank(): int
    {
        return match ($this) {
            self::Member => 0,
            self::Moderator => 1,
            self::Admin => 2,
        };
    }
}
