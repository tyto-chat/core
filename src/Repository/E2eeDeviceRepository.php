<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\E2eeAccount;
use App\Entity\E2eeDevice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<E2eeDevice>
 */
class E2eeDeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, E2eeDevice::class);
    }

    /** @return list<E2eeDevice> oldest first */
    public function findActiveByAccount(E2eeAccount $account): array
    {
        /** @var list<E2eeDevice> $devices */
        $devices = $this->createQueryBuilder('d')
            ->where('d.account = :account')
            ->andWhere('d.revokedAt IS NULL')
            ->andWhere('d.generation = :generation')
            ->setParameter('account', $account)
            ->setParameter('generation', $account->getGeneration())
            ->orderBy('d.createdAt', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $devices;
    }

    /** @return list<E2eeDevice> oldest revocation first */
    public function findRevokedByAccount(E2eeAccount $account): array
    {
        /** @var list<E2eeDevice> $devices */
        $devices = $this->createQueryBuilder('d')
            ->where('d.account = :account')
            ->andWhere('d.revokedAt IS NOT NULL')
            ->setParameter('account', $account)
            ->orderBy('d.revokedAt', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $devices;
    }
}
