<?php

declare(strict_types=1);

namespace App\Dto\CommunityPin;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

readonly class ReorderPinnedCommunitiesDto
{
    /**
     * @param list<int> $communityIds community ids in the desired display order
     */
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Count(min: 1)]
        #[Assert\All([
            new Assert\Type('integer'),
            new Assert\Positive(),
        ])]
        #[Groups(['community_pin:write'])]
        public array $communityIds,
    ) {
    }
}
