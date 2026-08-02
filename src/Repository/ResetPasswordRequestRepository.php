<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ResetPasswordRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ResetPasswordRequest>
 */
class ResetPasswordRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResetPasswordRequest::class);
    }

    public function findOneNotExpiredByEmailAndToken(string $email, string $token): ?ResetPasswordRequest
    {
        return $this->createQueryBuilder('r')
            ->where('r.email = :email')
            ->andWhere('r.token = :token')
            ->andWhere('r.expiresAt > :now')
            ->andWhere('r.usedAt is null')
            ->setParameter('email', $email)
            ->setParameter('token', hash('sha256', $token))
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
