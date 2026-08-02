<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AdminAuditLog;
use App\Enum\Admin\AdminAuditAction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminAuditLog>
 */
class AdminAuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminAuditLog::class);
    }

    /**
     * @return array{rows: list<AdminAuditLog>, total: int}
     */
    public function findPaginated(
        ?AdminAuditAction $action = null,
        ?int $actorId = null,
        ?string $targetType = null,
        ?int $targetId = null,
        int $page = 1,
        int $perPage = 25,
    ): array {
        $qb = $this->createQueryBuilder('a');

        if (null !== $action) {
            $qb->andWhere('a.action = :action')->setParameter('action', $action);
        }
        if (null !== $actorId) {
            $qb->andWhere('a.actor = :actorId')->setParameter('actorId', $actorId);
        }
        if (null !== $targetType) {
            $qb->andWhere('a.targetType = :targetType')->setParameter('targetType', $targetType);
        }
        if (null !== $targetId) {
            $qb->andWhere('a.targetId = :targetId')->setParameter('targetId', $targetId);
        }

        $countQb = (clone $qb)->select('COUNT(a.id)');
        /** @var int $total */
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        /** @var list<AdminAuditLog> $rows */
        $rows = $qb
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['rows' => $rows, 'total' => $total];
    }
}
