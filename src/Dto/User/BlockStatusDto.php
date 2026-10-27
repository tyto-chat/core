<?php

declare(strict_types=1);

namespace App\Dto\User;

use Symfony\Component\Serializer\Attribute\Groups;

final readonly class BlockStatusDto
{
    public function __construct(
        #[Groups(['user_blocks:read'])]
        public int $userId,
        #[Groups(['user_blocks:read'])]
        public bool $blocked,
        #[Groups(['user_blocks:read'])]
        public bool $blockable,
        #[Groups(['user_blocks:read'])]
        public bool $inEffect,
    ) {
    }
}
