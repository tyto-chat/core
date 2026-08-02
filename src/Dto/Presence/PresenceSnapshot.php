<?php

declare(strict_types=1);

namespace App\Dto\Presence;

use App\Enum\Presence\PresenceState;

final readonly class PresenceSnapshot
{
    public function __construct(
        public int $userId,
        public PresenceState $state,
    ) {
    }
}
