<?php

declare(strict_types=1);

namespace App\Dto\Presence;

use Symfony\Component\Serializer\Attribute\Groups;

final readonly class PresenceSamplePointDto
{
    public function __construct(
        #[Groups(['presence:read'])]
        public string $sampledAt,
        #[Groups(['presence:read'])]
        public int $membersOnline,
        #[Groups(['presence:read'])]
        public int $guestsOnline,
    ) {
    }
}
