<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiKey;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiKey>
 */
class ApiKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiKey::class);
    }

    /**
     * @return ApiKey[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('k')
            ->where('k.user = :user')
            ->setParameter('user', $user)
            ->orderBy('k.revokedAt', Order::Ascending->value)
            ->addOrderBy('k.createdAt', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    public function findOneByTokenHash(string $tokenHash): ?ApiKey
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }
}
