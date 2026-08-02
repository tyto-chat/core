<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Community;
use App\Entity\CommunityPin;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CommunityPin>
 */
class CommunityPinRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommunityPin::class);
    }

    /**
     * @return CommunityPin[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('c')
            ->join('p.community', 'c')
            ->where('p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('p.position', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    public function findOneByUserAndCommunity(User $user, Community $community): ?CommunityPin
    {
        return $this->findOneBy(['user' => $user, 'community' => $community]);
    }

    public function findMaxPosition(User $user): ?int
    {
        $value = $this->createQueryBuilder('p')
            ->select('MAX(p.position)')
            ->where('p.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $value ? null : (int) $value;
    }
}
