<?php

declare(strict_types=1);

namespace App\Enum\Presence;

enum PresenceState: string
{
    case Online = 'online';
    case Away = 'away';
    case Dnd = 'dnd';
    case Offline = 'offline';
}
