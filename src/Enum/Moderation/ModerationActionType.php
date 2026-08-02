<?php

declare(strict_types=1);

namespace App\Enum\Moderation;

enum ModerationActionType: string
{
    case Warn = 'warn';
    case Timeout = 'timeout';
    case Ban = 'ban';
    case ServerBan = 'server_ban';
}
