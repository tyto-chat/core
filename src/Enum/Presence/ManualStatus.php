<?php

declare(strict_types=1);

namespace App\Enum\Presence;

enum ManualStatus: string
{
    case Away = 'away';
    case Dnd = 'dnd';
    case Invisible = 'invisible';

    public function toState(): PresenceState
    {
        return match ($this) {
            self::Away => PresenceState::Away,
            self::Dnd => PresenceState::Dnd,
            self::Invisible => PresenceState::Offline,
        };
    }
}
