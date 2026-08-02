<?php

declare(strict_types=1);

namespace App\Service\Community;

use App\Entity\Community;
use App\Entity\CommunityMember;
use App\Entity\User;
use App\Enum\Community\CommunityRole;
use App\Repository\CommunityMemberRepository;

class CommunityMembershipService implements CommunityMembershipServiceInterface
{
    public function __construct(
        private readonly CommunityMemberRepository $communityMemberRepository,
    ) {
    }

    public function findById(int $id, Community $community): ?CommunityMember
    {
        return $this->communityMemberRepository->findOneBy(['id' => $id, 'community' => $community]);
    }

    public function findOneByUserAndCommunity(User $user, Community $community): ?CommunityMember
    {
        return $this->communityMemberRepository->findOneByUserAndCommunity($user, $community);
    }

    public function findMutedMemberships(User $user): array
    {
        return $this->communityMemberRepository->findBy(['user' => $user, 'notificationsMuted' => true]);
    }

    public function findByCommunity(Community $community): array
    {
        return $this->communityMemberRepository->findByCommunity($community);
    }

    public function isMember(User $user, Community $community): bool
    {
        return $this->communityMemberRepository->isMember($user, $community);
    }

    public function isAdmin(User $user, Community $community): bool
    {
        return $this->communityMemberRepository->isAdmin($user, $community);
    }

    public function isModerator(User $user, Community $community): bool
    {
        return $this->communityMemberRepository->isModerator($user, $community);
    }

    public function findRole(User $user, Community $community): ?CommunityRole
    {
        return $this->communityMemberRepository->findRole($user, $community);
    }

    public function countByRole(Community $community, CommunityRole $role): int
    {
        return $this->communityMemberRepository->countByRole($community, $role);
    }

    public function countMembers(Community $community): int
    {
        return $this->communityMemberRepository->countByCommunity($community);
    }

    public function countMembersPerCommunity(): array
    {
        return $this->communityMemberRepository->countPerCommunity();
    }

    public function findMemberUserIds(Community $community): array
    {
        return $this->communityMemberRepository->findMemberUserIds($community);
    }

    public function findModeratorUsers(Community $community): array
    {
        return $this->communityMemberRepository->findModeratorUsers($community);
    }

    public function existsSharedCommunity(User $a, User $b): bool
    {
        return $this->communityMemberRepository->existsSharedCommunity($a, $b);
    }

    public function findUsersSharingCommunity(User $caller, ?string $search = null, int $limit = 20): array
    {
        return $this->communityMemberRepository->findUsersSharingCommunity($caller, $search, $limit);
    }
}
