<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Community;
use App\Entity\CommunityMember;
use App\Entity\User;
use App\Enum\Community\CommunityRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CommunityMember>
 */
class CommunityMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommunityMember::class);
    }

    public function isMember(User $user, Community $community): bool
    {
        return null !== $this->findOneByUserAndCommunity($user, $community);
    }

    public function isAdmin(User $user, Community $community): bool
    {
        return CommunityRole::Admin === $this->findRole($user, $community);
    }

    public function findRole(User $user, Community $community): ?CommunityRole
    {
        return $this->findOneByUserAndCommunity($user, $community)?->getRole();
    }

    public function findOneByUserAndCommunity(User $user, Community $community): ?CommunityMember
    {
        return $this->findOneBy(['user' => $user, 'community' => $community]);
    }

    public function isModerator(User $user, Community $community): bool
    {
        return CommunityRole::Moderator === $this->findRole($user, $community);
    }

    public function countByRole(Community $community, CommunityRole $role): int
    {
        return (int) $this->createQueryBuilder('cm')
            ->select('COUNT(cm.id)')
            ->where('cm.community = :community')
            ->andWhere('cm.role = :role')
            ->setParameter('community', $community)
            ->setParameter('role', $role->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByCommunity(Community $community): int
    {
        return (int) $this->createQueryBuilder('cm')
            ->select('COUNT(cm.id)')
            ->where('cm.community = :community')
            ->setParameter('community', $community)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return array<int, int> community id → member count */
    public function countPerCommunity(): array
    {
        /** @var list<array{communityId: int, memberCount: int}> $rows */
        $rows = $this->createQueryBuilder('cm')
            ->select('IDENTITY(cm.community) AS communityId', 'COUNT(cm.id) AS memberCount')
            ->groupBy('cm.community')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['communityId']] = (int) $row['memberCount'];
        }

        return $counts;
    }

    /** @return CommunityMember[] */
    public function findByCommunity(Community $community): array
    {
        return $this->findBy(['community' => $community], ['joinedAt' => 'ASC']);
    }

    /** @return list<User> */
    public function findModeratorUsers(Community $community): array
    {
        /** @var list<CommunityMember> $rows */
        $rows = $this->createQueryBuilder('cm')
            ->where('cm.community = :community')
            ->andWhere('cm.role IN (:roles)')
            ->setParameter('community', $community)
            ->setParameter('roles', [CommunityRole::Admin->value, CommunityRole::Moderator->value])
            ->getQuery()
            ->getResult();

        return array_map(static fn (CommunityMember $m): User => $m->getUser(), $rows);
    }

    /**
     * @return int[]
     */
    public function findMemberUserIds(Community $community): array
    {
        /** @var list<int> $rows */
        $rows = $this->createQueryBuilder('cm')
            ->select('IDENTITY(cm.user) AS userId')
            ->where('cm.community = :community')
            ->setParameter('community', $community)
            ->getQuery()
            ->getSingleColumnResult();

        return $rows;
    }

    public function existsSharedCommunity(User $a, User $b): bool
    {
        if ($a->getId() === $b->getId()) {
            return false;
        }

        $result = $this->createQueryBuilder('cma')
            ->select('1')
            ->innerJoin(CommunityMember::class, 'cmb', 'WITH', 'cmb.community = cma.community')
            ->where('cma.user = :a')
            ->andWhere('cmb.user = :b')
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return null !== $result;
    }

    /**
     * @return array<int, array{communityId: int, communityIdentifier: string, role: CommunityRole}>
     */
    public function findMembershipsForUser(User $user): array
    {
        $rows = $this->createQueryBuilder('cm')
            ->select('IDENTITY(cm.community) AS communityId', 'c.identifier AS communityIdentifier', 'cm.role AS role')
            ->join('cm.community', 'c')
            ->where('cm.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $row): array {
            return [
                'communityId' => (int) $row['communityId'],
                'communityIdentifier' => (string) $row['communityIdentifier'],
                'role' => $row['role'] instanceof CommunityRole ? $row['role'] : CommunityRole::from((string) $row['role']),
            ];
        }, $rows);
    }

    /**
     * @return User[] users who share at least one community w/ $caller, optionally filtered by partial display name / email
     */
    public function findUsersSharingCommunity(User $caller, ?string $search = null, int $limit = 20): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT u')
            ->from(User::class, 'u')
            ->innerJoin(CommunityMember::class, 'cmb', 'WITH', 'cmb.user = u')
            ->innerJoin(CommunityMember::class, 'cma', 'WITH', 'cma.community = cmb.community AND cma.user = :caller')
            ->leftJoin('u.profile', 'p')
            ->where('u != :caller')
            ->setParameter('caller', $caller)
            ->setMaxResults($limit);

        if (null !== $search && '' !== trim($search)) {
            $term = '%'.addcslashes(trim($search), '%_\\').'%';
            $qb->andWhere('p.name LIKE :term OR u.email LIKE :term')
                ->setParameter('term', $term);
        }

        /** @var User[] $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }
}
