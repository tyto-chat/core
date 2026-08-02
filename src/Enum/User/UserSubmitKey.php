<?php

declare(strict_types=1);

namespace App\Enum\User;

use App\Enum\BackedEnumValuesTrait;

enum UserSubmitKey: string
{
    use BackedEnumValuesTrait;

    case Enter = 'enter';
    case ShiftEnter = 'shift+enter';
    case CtrlEnter = 'ctrl+enter';
    case None = 'none';
}
