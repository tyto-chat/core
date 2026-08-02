<?php

declare(strict_types=1);

namespace App\Dto\Stats;

use Symfony\Component\Serializer\Attribute\Groups;

class CommunityStatsDto
{
    public function __construct(
        #[Groups(['stats:read'])]
        public readonly int $id,
        #[Groups(['stats:read'])]
        public readonly string $identifier,
        #[Groups(['stats:read'])]
        public readonly string $name,
        #[Groups(['stats:read'])]
        public readonly int $channelCount,
        #[Groups(['stats:read'])]
        public readonly int $memberCount,
        #[Groups(['stats:read'])]
        public readonly int $messageCount,
        #[Groups(['stats:read'])]
        public readonly int $attachmentCount,
        #[Groups(['stats:read'])]
        public readonly int $attachmentsSize,
    ) {
    }
}
