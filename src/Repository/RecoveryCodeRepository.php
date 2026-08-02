<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecoveryCode;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecoveryCode>
 */
class RecoveryCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecoveryCode::class);
    }

    public function countUnused(User $user): int
    {
        return $this->count(['user' => $user, 'usedAt' => null]);
    }

    /** @return RecoveryCode[] */
    public function findUnusedByUser(User $user): array
    {
        return $this->findBy(['user' => $user, 'usedAt' => null]);
    }

    public function tryConsume(RecoveryCode $code): bool
    {
        $affected = $this->createQueryBuilder('rc')
            ->update()
            ->set('rc.usedAt', ':now')
            ->where('rc.id = :id')
            ->andWhere('rc.usedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('id', $code->getId())
            ->getQuery()
            ->execute();

        return 1 === (int) $affected;
    }

    public function deleteAllForUser(User $user): void
    {
        $this->createQueryBuilder('rc')
            ->delete()
            ->where('rc.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
