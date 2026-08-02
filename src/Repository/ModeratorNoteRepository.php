<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Community;
use App\Entity\ModeratorNote;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ModeratorNote>
 */
class ModeratorNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModeratorNote::class);
    }

    /**
     * @return ModeratorNote[]
     */
    public function findByUserAndCommunity(Community $community, User $targetUser): array
    {
        return $this->createQueryBuilder('mn')
            ->where('mn.community = :community')
            ->andWhere('mn.targetUser = :user')
            ->setParameter('community', $community)
            ->setParameter('user', $targetUser)
            ->orderBy('mn.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
