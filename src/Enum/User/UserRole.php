<?php

declare(strict_types=1);

namespace App\Enum\User;

use App\Enum\BackedEnumValuesTrait;

enum UserRole: string
{
    use BackedEnumValuesTrait;

    case User = 'ROLE_USER';
    case Admin = 'ROLE_ADMIN';
}
