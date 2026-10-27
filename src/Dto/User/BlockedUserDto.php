<?php

declare(strict_types=1);

namespace App\Dto\User;

use App\Entity\MediaObject;
use App\Entity\UserBlock;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;

final class BlockedUserDto
{
    #[Groups(['user_blocks:read'])]
    public ?string $avatarUrl = null;

    public function __construct(
        #[Groups(['user_blocks:read'])]
        public readonly int $userId,
        #[Groups(['user_blocks:read'])]
        public readonly ?string $name,
        #[Groups(['user_blocks:read'])]
        public readonly string $blockedAt,
        #[Groups(['user_blocks:read'])]
        public readonly bool $inEffect,
        #[Ignore]
        public readonly ?MediaObject $avatar = null,
    ) {
    }

    public static function fromBlock(UserBlock $block, bool $inEffect): self
    {
        $blocked = $block->getBlocked();

        return new self(
            (int) $blocked->getId(),
            $blocked->getProfile()?->getName(),
            $block->getCreatedAt()->format(\DATE_ATOM),
            $inEffect,
            $blocked->getProfile()?->getAvatar(),
        );
    }
}
