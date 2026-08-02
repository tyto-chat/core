<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PushSubscription>
 */
class PushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushSubscription::class);
    }

    public function findOneByEndpoint(string $endpoint): ?PushSubscription
    {
        return $this->findOneBy(['endpoint' => $endpoint]);
    }

    /** @return PushSubscription[] */
    public function findByUserId(int $userId): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();
    }

    public function countByUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }

    /** @param list<string> $endpoints */
    public function deleteByEndpoints(array $endpoints): int
    {
        if ([] === $endpoints) {
            return 0;
        }

        return (int) $this->createQueryBuilder('ps')
            ->delete()
            ->where('ps.endpoint IN (:endpoints)')
            ->setParameter('endpoints', $endpoints)
            ->getQuery()
            ->execute();
    }

    public function deleteAllForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('ps')
            ->delete()
            ->where('ps.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
