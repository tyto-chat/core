<?php

declare(strict_types=1);

namespace App\Dto\UserGroup;

use Symfony\Component\Serializer\Attribute\Groups;

final readonly class MyGroupDto
{
    public function __construct(
        #[Groups(['my_groups:read'])]
        public string $identifier,
        #[Groups(['my_groups:read'])]
        public string $name,
        #[Groups(['my_groups:read'])]
        public ?string $icon,
        #[Groups(['my_groups:read'])]
        public ?string $color,
        #[Groups(['my_groups:read'])]
        public string $communityIdentifier,
        #[Groups(['my_groups:read'])]
        public string $communityName,
        #[Groups(['my_groups:read'])]
        public int $memberCount,
        #[Groups(['my_groups:read'])]
        public bool $isOwner,
    ) {
    }
}
