<?php

declare(strict_types=1);

namespace App\Dto\Community;

use Symfony\Component\Serializer\Attribute\Groups;

class MyCommunityMembershipDto
{
    public function __construct(
        #[Groups(['community_membership:read'])]
        public int $communityId = 0,
        #[Groups(['community_membership:read'])]
        public string $communityIdentifier = '',
        #[Groups(['community_membership:read'])]
        public string $role = '',
    ) {
    }
}
