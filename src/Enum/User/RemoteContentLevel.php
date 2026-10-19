<?php

declare(strict_types=1);

namespace App\Enum\User;

use App\Enum\BackedEnumValuesTrait;

enum RemoteContentLevel: string
{
    use BackedEnumValuesTrait;

    case Custom = 'custom';
    case All = 'all';
}
