<?php

declare(strict_types=1);

namespace App\Enum\Community;

use App\Enum\BackedEnumValuesTrait;

enum CommunityRole: string
{
    use BackedEnumValuesTrait;

    case Member = 'member';
    case Moderator = 'moderator';
    case Admin = 'admin';
}
