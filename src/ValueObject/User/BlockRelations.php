<?php

declare(strict_types=1);

namespace App\ValueObject\User;

final readonly class BlockRelations
{
    /**
     * @param list<int> $blockedIds enforced blocks the user holds
     * @param list<int> $blockerIds enforced blocks held against the user
     */
    public function __construct(
        public array $blockedIds = [],
        public array $blockerIds = [],
    ) {
    }

    public function blocks(int $otherId): bool
    {
        return in_array($otherId, $this->blockedIds, true);
    }

    public function isBlockedBy(int $otherId): bool
    {
        return in_array($otherId, $this->blockerIds, true);
    }

    public function hasAnyWith(int $otherId): bool
    {
        return $this->blocks($otherId) || $this->isBlockedBy($otherId);
    }
}
