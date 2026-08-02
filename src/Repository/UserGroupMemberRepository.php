<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Community;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\UserGroupMember;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserGroupMember>
 */
class UserGroupMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserGroupMember::class);
    }

    public function findOneByUserAndGroup(User $user, UserGroup $group): ?UserGroupMember
    {
        return $this->findOneBy(['user' => $user, 'userGroup' => $group]);
    }

    public function isMember(User $user, UserGroup $group): bool
    {
        return null !== $this->findOneByUserAndGroup($user, $group);
    }

    /** @return UserGroupMember[] */
    public function findByGroup(UserGroup $group): array
    {
        return $this->findBy(['userGroup' => $group], ['addedAt' => 'ASC']);
    }

    public function countByGroup(UserGroup $group): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.userGroup = :group')
            ->setParameter('group', $group)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<int, int> group id → member count
     */
    public function countPerGroupInCommunity(Community $community): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.userGroup) AS groupId', 'COUNT(m.id) AS members')
            ->join('m.userGroup', 'g')
            ->where('g.community = :community')
            ->setParameter('community', $community)
            ->groupBy('groupId')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['groupId']] = (int) $row['members'];
        }

        return $counts;
    }

    /**
     * @param int[] $groupIds
     *
     * @return array<int, int> group id → member count
     */
    public function countForGroups(array $groupIds): array
    {
        if ([] === $groupIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.userGroup) AS groupId', 'COUNT(m.id) AS members')
            ->where('m.userGroup IN (:ids)')
            ->setParameter('ids', $groupIds)
            ->groupBy('groupId')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['groupId']] = (int) $row['members'];
        }

        return $counts;
    }

    /**
     * @return array<int, UserGroup[]>
     */
    public function findGroupMembershipsIndexedByUser(Community $community): array
    {
        /** @var UserGroupMember[] $memberships */
        $memberships = $this->createQueryBuilder('m')
            ->join('m.userGroup', 'g')
            ->where('g.community = :community')
            ->setParameter('community', $community)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($memberships as $membership) {
            $userId = $membership->getUserId();
            if (null === $userId) {
                continue;
            }
            $indexed[$userId][] = $membership->getUserGroup();
        }

        return $indexed;
    }

    public function deleteForUserInCommunity(User $user, Community $community): int
    {
        return (int) $this->getEntityManager()
            ->createQuery(
                'DELETE FROM App\Entity\UserGroupMember ugm
                 WHERE ugm.user = :user
                 AND ugm.userGroup IN (SELECT g.id FROM App\Entity\UserGroup g WHERE g.community = :community)'
            )
            ->setParameter('user', $user)
            ->setParameter('community', $community)
            ->execute();
    }
}
