<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Appeal;
use App\Entity\Community;
use App\Entity\ModerationAction;
use App\Enum\Moderation\AppealStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Appeal>
 */
class AppealRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Appeal::class);
    }

    public function findOneByAction(ModerationAction $action): ?Appeal
    {
        return $this->findOneBy(['moderationAction' => $action]);
    }

    /** @return list<Appeal> */
    public function findForCommunity(Community $community, ?AppealStatus $status, int $page = 1, int $perPage = 50): array
    {
        $qb = $this->baseCommunityQb($community)
            ->orderBy('a.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);
        $this->andStatus($qb, $status);

        return $qb->getQuery()->getResult();
    }

    public function countForCommunity(Community $community, ?AppealStatus $status): int
    {
        $qb = $this->baseCommunityQb($community)->select('COUNT(a.id)');
        $this->andStatus($qb, $status);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function baseCommunityQb(Community $community): QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->join('a.moderationAction', 'ma')
            ->where('ma.community = :community')
            ->setParameter('community', $community);
    }

    private function andStatus(QueryBuilder $qb, ?AppealStatus $status): void
    {
        if (null !== $status) {
            $qb->andWhere('a.status = :status')->setParameter('status', $status->value);
        }
    }
}
