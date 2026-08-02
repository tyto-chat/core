<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Community;
use App\Entity\PresenceSample;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PresenceSample>
 */
class PresenceSampleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PresenceSample::class);
    }

    /** @return list<PresenceSample> */
    public function findForCommunitySince(Community $community, \DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.community = :community')
            ->andWhere('s.sampledAt >= :since')
            ->setParameter('community', $community)
            ->setParameter('since', $since)
            ->orderBy('s.sampledAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        return $this->createQueryBuilder('s')
            ->delete()
            ->andWhere('s.sampledAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
