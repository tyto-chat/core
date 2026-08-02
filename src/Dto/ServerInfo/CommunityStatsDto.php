<?php

declare(strict_types=1);

namespace App\Dto\ServerInfo;

use Symfony\Component\Serializer\Attribute\Groups;

final readonly class CommunityStatsDto
{
    public function __construct(
        #[Groups(['server_info:read'])]
        public int $memberCount,
        #[Groups(['server_info:read'])]
        public int $onlineCount,
        #[Groups(['server_info:read'])]
        public int $channelCount,
    ) {
    }
}
