<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\E2eeAccount;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<E2eeAccount>
 */
class E2eeAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, E2eeAccount::class);
    }

    public function findOneByUser(User $user): ?E2eeAccount
    {
        return $this->findOneBy(['user' => $user]);
    }
}
