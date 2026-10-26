<?php

declare(strict_types=1);

namespace App\Dto\User;

final readonly class BlockRelations
{
    /**
     * @param list<int> $blockedByUser enforced blocks the user holds
     * @param list<int> $blockingUser  enforced blocks held against the user
     */
    public function __construct(
        public array $blockedByUser = [],
        public array $blockingUser = [],
    ) {
    }

    public function hasAnyWith(int $otherId): bool
    {
        return in_array($otherId, $this->blockedByUser, true) || in_array($otherId, $this->blockingUser, true);
    }
}
