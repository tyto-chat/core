<?php

declare(strict_types=1);

namespace App\Dto\E2ee;

use Symfony\Component\Serializer\Attribute\Groups;

final readonly class GroupStateDto
{
    /** @param list<int> $frozenGenerations */
    public function __construct(
        #[Groups(['e2ee:read'])]
        public int $epoch,
        #[Groups(['e2ee:read'])]
        public int $generation,
        #[Groups(['e2ee:read'])]
        public ?string $groupInfo,
        #[Groups(['e2ee:read'])]
        public array $frozenGenerations,
    ) {
    }
}
