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
}
