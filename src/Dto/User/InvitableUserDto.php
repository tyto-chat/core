<?php

declare(strict_types=1);

namespace App\Dto\User;

use Symfony\Component\Serializer\Attribute\Groups;

final readonly class InvitableUserDto
{
    public function __construct(
        #[Groups(['invitable_users:read'])]
        public int $id = 0,
        #[Groups(['invitable_users:read'])]
        public ?string $name = null,
        #[Groups(['invitable_users:read'])]
        public ?string $avatarUrl = null,
    ) {
    }
}
