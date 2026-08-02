<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Community;
use App\Entity\CommunityInvite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CommunityInvite>
 */
class CommunityInviteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommunityInvite::class);
    }

    public function findOneByToken(string $token): ?CommunityInvite
    {
        return $this->findOneBy(['token' => $token]);
    }

    /** @return CommunityInvite[] */
    public function findByCommunity(Community $community): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.community = :community')
            ->setParameter('community', $community)
            ->orderBy('i.createdAt', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    public function tryConsumeUse(CommunityInvite $invite): bool
    {
        // Guarded UPDATE is the concurrency gate — a load-check-save rewrite lets parallel accepts overshoot maxUses.
        $affected = $this->getEntityManager()->createQuery(
            'UPDATE App\Entity\CommunityInvite i
             SET i.useCount = i.useCount + 1
             WHERE i.id = :id AND (i.maxUses IS NULL OR i.useCount < i.maxUses)'
        )->setParameter('id', $invite->getId())->execute();

        $this->getEntityManager()->refresh($invite);

        return 1 === $affected;
    }

    public function refundUse(CommunityInvite $invite): void
    {
        $this->getEntityManager()->createQuery(
            'UPDATE App\Entity\CommunityInvite i
             SET i.useCount = i.useCount - 1
             WHERE i.id = :id AND i.useCount > 0'
        )->setParameter('id', $invite->getId())->execute();

        $this->getEntityManager()->refresh($invite);
    }
}
