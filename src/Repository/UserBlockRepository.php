<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserBlock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserBlock>
 */
class UserBlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserBlock::class);
    }

    public function countByBlocker(User $blocker): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.blocker = :blocker')
            ->setParameter('blocker', $blocker)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneByPair(User $blocker, User $blocked): ?UserBlock
    {
        return $this->findOneBy(['blocker' => $blocker, 'blocked' => $blocked]);
    }

    /** @return list<UserBlock> newest first */
    public function findByBlocker(User $blocker): array
    {
        /** @var list<UserBlock> $rows */
        $rows = $this->createQueryBuilder('b')
            ->addSelect('u', 'p', 'a')
            ->innerJoin('b.blocked', 'u')
            ->leftJoin('u.profile', 'p')
            ->leftJoin('p.avatar', 'a')
            ->where('b.blocker = :blocker')
            ->setParameter('blocker', $blocker)
            ->orderBy('b.createdAt', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @param list<int> $blockerIds
     *
     * @return array<int, list<int>> blockerId → blockedIds
     */
    public function findBlockedIdsByBlockers(array $blockerIds): array
    {
        if ([] === $blockerIds) {
            return [];
        }

        /** @var list<array{blockerId: int|string, blockedId: int|string}> $rows */
        $rows = $this->createQueryBuilder('b')
            ->select('IDENTITY(b.blocker) AS blockerId', 'IDENTITY(b.blocked) AS blockedId')
            ->where('IDENTITY(b.blocker) IN (:ids)')
            ->setParameter('ids', $blockerIds)
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['blockerId']][] = (int) $row['blockedId'];
        }

        return $map;
    }

    /**
     * @param list<int> $blockerIds
     *
     * @return list<int>
     */
    public function findBlockerIdsAmong(int $blockedId, array $blockerIds): array
    {
        if ([] === $blockerIds) {
            return [];
        }

        /** @var list<array{blockerId: int|string}> $rows */
        $rows = $this->createQueryBuilder('b')
            ->select('IDENTITY(b.blocker) AS blockerId')
            ->where('IDENTITY(b.blocked) = :blocked')
            ->andWhere('IDENTITY(b.blocker) IN (:ids)')
            ->setParameter('blocked', $blockedId)
            ->setParameter('ids', $blockerIds)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['blockerId'], $rows);
    }

    /**
     * @param list<int> $otherIds
     *
     * @return list<array{blockerId: int, blockedId: int}>
     */
    public function findPairsInvolving(int $userId, array $otherIds): array
    {
        if ([] === $otherIds) {
            return [];
        }

        /** @var list<array{blockerId: int|string, blockedId: int|string}> $rows */
        $rows = $this->createQueryBuilder('b')
            ->select('IDENTITY(b.blocker) AS blockerId', 'IDENTITY(b.blocked) AS blockedId')
            ->where('(IDENTITY(b.blocker) = :user AND IDENTITY(b.blocked) IN (:others)) OR (IDENTITY(b.blocked) = :user AND IDENTITY(b.blocker) IN (:others))')
            ->setParameter('user', $userId)
            ->setParameter('others', $otherIds)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => ['blockerId' => (int) $row['blockerId'], 'blockedId' => (int) $row['blockedId']],
            $rows,
        );
    }

    public function deleteAllInvolving(User $user): void
    {
        $this->createQueryBuilder('b')
            ->delete()
            ->where('b.blocker = :user OR b.blocked = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
