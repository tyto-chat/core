<?php

declare(strict_types=1);

namespace App\Dto\User;

use App\Entity\MediaObject;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;

final class InvitableUserDto
{
    #[Groups(['invitable_users:read'])]
    public ?string $avatarUrl = null;

    public function __construct(
        #[Groups(['invitable_users:read'])]
        public readonly int $id = 0,
        #[Groups(['invitable_users:read'])]
        public readonly ?string $name = null,
        #[Ignore]
        public readonly ?MediaObject $avatar = null,
    ) {
    }
}
