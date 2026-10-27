<?php

declare(strict_types=1);

namespace App\Enum\Conversation;

enum ReadOnlyReason: string
{
    case Blocked = 'blocked';
    case Unavailable = 'unavailable';
}
