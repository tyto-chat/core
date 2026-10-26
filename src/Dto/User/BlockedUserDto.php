<?php

declare(strict_types=1);

namespace App\Dto\User;

use App\Entity\UserBlock;
use Symfony\Component\Serializer\Attribute\Groups;

final readonly class BlockedUserDto
{
    public function __construct(
        #[Groups(['user_blocks:read'])]
        public int $userId,
        #[Groups(['user_blocks:read'])]
        public ?string $name,
        #[Groups(['user_blocks:read'])]
        public ?string $avatarUrl,
        #[Groups(['user_blocks:read'])]
        public string $blockedAt,
        #[Groups(['user_blocks:read'])]
        public bool $inEffect,
    ) {
    }

    public static function fromBlock(UserBlock $block, bool $inEffect, ?string $avatarUrl): self
    {
        $blocked = $block->getBlocked();

        return new self(
            (int) $blocked->getId(),
            $blocked->getProfile()?->getName(),
            $avatarUrl,
            $block->getCreatedAt()->format(\DATE_ATOM),
            $inEffect,
        );
    }
}
