<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChannelSection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChannelSection>
 */
class ChannelSectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChannelSection::class);
    }

    public function findMaxPosition(\App\Entity\Community $community): int
    {
        $max = $this->createQueryBuilder('s')
            ->select('MAX(s.position)')
            ->andWhere('s.community = :c')->setParameter('c', $community)
            ->getQuery()->getSingleScalarResult();

        return null === $max ? -1 : (int) $max;
    }
}
