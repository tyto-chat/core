<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\E2eeKeyPackage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<E2eeKeyPackage>
 */
class E2eeKeyPackageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, E2eeKeyPackage::class);
    }
}
