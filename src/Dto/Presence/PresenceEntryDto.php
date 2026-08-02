<?php

declare(strict_types=1);

namespace App\Dto\Presence;

use App\Enum\Presence\PresenceState;
use Symfony\Component\Serializer\Attribute\Groups;

final readonly class PresenceEntryDto
{
    public function __construct(
        #[Groups(['presence:read'])]
        public int $userId = 0,
        #[Groups(['presence:read'])]
        public PresenceState $state = PresenceState::Offline,
    ) {
    }
}
