<?php

declare(strict_types=1);

namespace App\Dto\Presence;

use Symfony\Component\Serializer\Attribute\Groups;

final readonly class CommunityOnlineDto
{
    /** @param PresenceEntryDto[] $users */
    public function __construct(
        #[Groups(['presence:read'])]
        public array $users = [],
    ) {
    }
}
