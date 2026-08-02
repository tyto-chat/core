<?php

declare(strict_types=1);

namespace App\Dto\Presence;

use App\Enum\Presence\ManualStatus;
use App\Enum\Presence\PresenceState;
use Symfony\Component\Serializer\Attribute\Groups;

/** Input + echo for the manual-status setter; null clears the override, `state` echoes the computed result. */
final readonly class SetManualStatusDto
{
    public function __construct(
        #[Groups(['presence:write'])]
        public ?ManualStatus $status = null,
        #[Groups(['presence:read'])]
        public ?PresenceState $state = null,
    ) {
    }
}
