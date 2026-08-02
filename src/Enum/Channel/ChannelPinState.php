<?php

declare(strict_types=1);

namespace App\Enum\Channel;

use App\Enum\BackedEnumValuesTrait;

/** Sidebar placement, null = normal section; Favorite and Hidden are mutually exclusive. */
enum ChannelPinState: string
{
    use BackedEnumValuesTrait;

    case Favorite = 'favorite';
    case Hidden = 'hidden';
}
