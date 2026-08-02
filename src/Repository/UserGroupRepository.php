<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Community;
use App\Entity\User;
use App\Entity\UserGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserGroup>
 */
class UserGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserGroup::class);
    }

    /** @return UserGroup[] */
    public function findByCommunity(Community $community): array
    {
        return $this->findBy(['community' => $community], ['createdAt' => 'ASC']);
    }

    /**
     * @return UserGroup[]
     */
    public function findVisibleByCommunity(Community $community, ?User $viewer, bool $isAdmin = false): array
    {
        if ($isAdmin || null === $viewer) {
            return $this->findByCommunity($community);
        }

        return $this->createQueryBuilder('g')
            ->leftJoin('g.members', 'm', 'WITH', 'm.user = :viewer')
            ->where('g.community = :community')
            ->andWhere('g.isHidden = false OR m.id IS NOT NULL')
            ->setParameter('community', $community)
            ->setParameter('viewer', $viewer)
            ->orderBy('g.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return UserGroup[]
     */
    public function findAllForUser(User $user): array
    {
        return $this->createQueryBuilder('g')
            ->join('g.community', 'c')->addSelect('c')
            ->leftJoin('g.members', 'm', 'WITH', 'm.user = :user')
            ->where('g.owner = :user OR m.id IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return UserGroup[]
     */
    public function findGroupsForUser(User $user, Community $community): array
    {
        return $this->createQueryBuilder('g')
            ->join('g.members', 'm')
            ->where('g.community = :community')
            ->andWhere('m.user = :user')
            ->setParameter('community', $community)
            ->setParameter('user', $user)
            ->orderBy('g.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function clearOwnerForUserInCommunity(User $user, Community $community): int
    {
        return (int) $this->getEntityManager()
            ->createQuery(
                'UPDATE App\Entity\UserGroup g SET g.owner = NULL
                 WHERE g.owner = :user AND g.community = :community'
            )
            ->setParameter('user', $user)
            ->setParameter('community', $community)
            ->execute();
    }
}
