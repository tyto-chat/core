<?php

declare(strict_types=1);

namespace App\Service\Community;

use App\Entity\Community;
use App\Entity\CommunityPin;
use App\Entity\User;

interface CommunityPinServiceInterface
{
    /**
     * @return CommunityPin[]
     */
    public function listForCurrentUser(): array;

    public function pinFor(User $user, Community $community): CommunityPin;

    public function pinForCurrentUser(Community $community): CommunityPin;

    public function unpin(Community $community): void;

    public function unpinFor(User $user, Community $community): void;

    /**
     * @param int[] $communityIds community ids in the desired display order
     */
    public function reorder(array $communityIds): void;
}
