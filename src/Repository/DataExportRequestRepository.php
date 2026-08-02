<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DataExportRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DataExportRequest>
 */
class DataExportRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DataExportRequest::class);
    }

    public function findActiveForUser(User $user): ?DataExportRequest
    {
        return $this->createQueryBuilder('r')
            ->where('r.user = :user')
            ->andWhere('r.status IN (:active)')
            ->setParameter('user', $user)
            ->setParameter('active', DataExportRequest::ACTIVE_STATUSES)
            ->orderBy('r.requestedAt', Order::Descending->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findMostRecentForUser(User $user): ?DataExportRequest
    {
        return $this->findOneBy(['user' => $user], ['requestedAt' => 'DESC']);
    }

    public function findOneByDownloadToken(string $token): ?DataExportRequest
    {
        return $this->findOneBy(['downloadToken' => $token]);
    }

    /**
     * @return DataExportRequest[]
     */
    public function findExpired(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status = :ready')
            ->andWhere('r.expiresAt < :now')
            ->setParameter('ready', DataExportRequest::STATUS_READY)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }
}
