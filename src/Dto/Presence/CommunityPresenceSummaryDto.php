<?php

declare(strict_types=1);

namespace App\Dto\Presence;

use Symfony\Component\Serializer\Attribute\Groups;

final readonly class CommunityPresenceSummaryDto
{
    public function __construct(
        #[Groups(['presence:read'])]
        public int $onlineCount = 0,
        #[Groups(['presence:read'])]
        public int $guestsOnline = 0,
    ) {
    }
}
