<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Challenge;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Challenge>
 */
class ChallengeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Challenge::class);
    }

    public function findLatestActiveByEmail(string $email): ?Challenge
    {
        return $this->createQueryBuilder('c')
            ->where('c.email = :email')
            ->andWhere('c.expiresAt > :now')
            ->andWhere('c.usedAt is null')
            ->setParameter('email', $email)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
