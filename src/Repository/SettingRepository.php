<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Setting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Setting>
 */
class SettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Setting::class);
    }

    /** @return array<string, Setting> keyed by setting key */
    public function findAllKeyed(): array
    {
        $rows = $this->findAll();
        $map = [];
        foreach ($rows as $row) {
            $map[$row->getKey()] = $row;
        }

        return $map;
    }

    public function findMostRecentlyUpdated(): ?Setting
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
