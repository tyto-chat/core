<?php

declare(strict_types=1);

namespace App\Enum\User;

use App\Enum\BackedEnumValuesTrait;

enum UserTheme: string
{
    use BackedEnumValuesTrait;

    case System = 'system';
    case Light = 'light';
    case Dark = 'dark';
}
