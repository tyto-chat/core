<?php

declare(strict_types=1);

namespace App\Dto\E2ee;

use Symfony\Component\Serializer\Attribute\Groups;

final readonly class ItemsDto
{
    /** @param list<OwnDeviceDto>|list<RevocationDto>|list<ClaimedKeyPackageDto>|list<CommitDto>|list<WelcomeDto>|list<LeaveRequestDto> $items */
    public function __construct(
        #[Groups(['e2ee:read'])]
        public array $items,
    ) {
    }
}
