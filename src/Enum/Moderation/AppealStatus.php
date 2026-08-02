<?php

declare(strict_types=1);

namespace App\Enum\Moderation;

enum AppealStatus: string
{
    case Pending = 'pending';
    case Upheld = 'upheld';
    case Overturned = 'overturned';

    public function isResolved(): bool
    {
        return self::Pending !== $this;
    }
}
