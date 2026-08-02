<?php

declare(strict_types=1);

namespace App\Service\Community;

use App\Entity\Community;
use App\Entity\CommunityMember;
use App\Entity\User;
use App\Enum\Community\CommunityRole;

interface CommunityMembershipServiceInterface
{
    public function findById(int $id, Community $community): ?CommunityMember;

    public function findOneByUserAndCommunity(User $user, Community $community): ?CommunityMember;

    /** @return CommunityMember[] memberships the user muted notifications on */
    public function findMutedMemberships(User $user): array;

    /** @return CommunityMember[] */
    public function findByCommunity(Community $community): array;

    public function isMember(User $user, Community $community): bool;

    public function isAdmin(User $user, Community $community): bool;

    public function isModerator(User $user, Community $community): bool;

    public function findRole(User $user, Community $community): ?CommunityRole;

    public function countByRole(Community $community, CommunityRole $role): int;

    public function countMembers(Community $community): int;

    /** @return array<int, int> community id → member count, one query for all communities */
    public function countMembersPerCommunity(): array;

    /**
     * @return int[]
     */
    public function findMemberUserIds(Community $community): array;

    /** @return User[] community admins + moderators */
    public function findModeratorUsers(Community $community): array;

    public function existsSharedCommunity(User $a, User $b): bool;

    /**
     * @return User[]
     */
    public function findUsersSharingCommunity(User $caller, ?string $search = null, int $limit = 20): array;
}
