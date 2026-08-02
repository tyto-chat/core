<?php

declare(strict_types=1);

namespace App\Enum\Channel;

use App\Enum\BackedEnumValuesTrait;

/** Absent preference row = the `Mentions` default. */
enum ChannelNotificationLevel: string
{
    use BackedEnumValuesTrait;

    case All = 'all';
    case Mentions = 'mentions';
    case None = 'none';
}
