<?php

declare(strict_types=1);

namespace App\Dto\CommunityPin;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

readonly class PinCommunityDto
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Positive]
        #[Groups(['community_pin:write'])]
        public int $communityId,
    ) {
    }
}
