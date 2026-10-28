<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserPreference;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserPreference>
 */
class UserPreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserPreference::class);
    }

    public function findForUser(User $user): ?UserPreference
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * @param list<int> $userIds
     *
     * @return list<int>
     */
    public function findUserIdsRefusingPlainDms(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.user) AS userId')
            ->where('p.user IN (:ids)')
            ->andWhere('p.refusePlainDms = true')
            ->setParameter('ids', $userIds)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(intval(...), $rows));
    }
}
