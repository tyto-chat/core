<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Community;
use App\Entity\Message;
use App\Entity\Report;
use App\Entity\User;
use App\Enum\Moderation\ReportStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Report>
 */
class ReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Report::class);
    }

    public function existsPendingByReporterAndMessage(User $reporter, Message $message): bool
    {
        return null !== $this->createPendingQb($reporter)
            ->andWhere('r.message = :message')
            ->setParameter('message', $message)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function existsPendingByReporterAndUser(User $reporter, User $reportedUser): bool
    {
        return null !== $this->createPendingQb($reporter)
            ->andWhere('r.message IS NULL')
            ->andWhere('r.reportedUser = :reportedUser')
            ->setParameter('reportedUser', $reportedUser)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<Report> */
    public function findForCommunity(Community $community, ?ReportStatus $status, int $page = 1, int $perPage = 50): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.community = :community')
            ->setParameter('community', $community)
            ->orderBy('r.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);
        $this->andStatus($qb, $status);

        return $qb->getQuery()->getResult();
    }

    public function countForCommunity(Community $community, ?ReportStatus $status): int
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.community = :community')
            ->setParameter('community', $community);
        $this->andStatus($qb, $status);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<Report> */
    public function findForAdmin(?ReportStatus $status, int $page = 1, int $perPage = 50): array
    {
        $qb = $this->createQueryBuilder('r')
            ->orderBy('r.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);
        $this->andStatus($qb, $status);

        return $qb->getQuery()->getResult();
    }

    public function countForAdmin(?ReportStatus $status): int
    {
        $qb = $this->createQueryBuilder('r')->select('COUNT(r.id)');
        $this->andStatus($qb, $status);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function createPendingQb(User $reporter): QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->where('r.reporter = :reporter')
            ->andWhere('r.status IN (:pending)')
            ->setParameter('reporter', $reporter)
            ->setParameter('pending', [ReportStatus::Open->value, ReportStatus::Escalated->value]);
    }

    private function andStatus(QueryBuilder $qb, ?ReportStatus $status): void
    {
        if (null !== $status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status->value);
        }
    }
}
