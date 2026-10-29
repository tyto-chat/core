<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\E2eeAccount;
use App\Entity\E2eeGroupEpoch;
use App\Entity\E2eeWelcome;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<E2eeWelcome>
 */
class E2eeWelcomeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, E2eeWelcome::class);
    }

    /** @return list<E2eeWelcome> oldest first */
    public function findUndelivered(E2eeAccount $account, ?string $deviceId, int $limit, ?E2eeWelcome $after = null): array
    {
        $qb = $this->createQueryBuilder('w')
            ->innerJoin('w.device', 'd')
            ->innerJoin('w.groupEpoch', 'e')
            ->addSelect('e')
            ->where('d.account = :account')
            ->andWhere('d.revokedAt IS NULL')
            ->andWhere('d.generation = :generation')
            ->andWhere('w.deliveredAt IS NULL')
            ->setParameter('account', $account)
            ->setParameter('generation', $account->getGeneration())
            ->orderBy('w.createdAt', 'ASC')
            ->addOrderBy('e.epoch', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->setMaxResults($limit);
        if (null !== $after) {
            $qb->andWhere('w.createdAt > :afterAt OR (w.createdAt = :afterAt AND (e.epoch > :afterEpoch OR (e.epoch = :afterEpoch AND w.id > :afterId)))')
                ->setParameter('afterAt', $after->getCreatedAt())
                ->setParameter('afterEpoch', $after->getGroupEpoch()->getEpoch())
                ->setParameter('afterId', $after->getId());
        }
        if (null !== $deviceId) {
            $qb->andWhere('d.id = :deviceId')->setParameter('deviceId', $deviceId);
        }

        /** @var list<E2eeWelcome> $welcomes */
        $welcomes = $qb->getQuery()->getResult();

        return $welcomes;
    }

    /** @return list<E2eeWelcome> */
    public function findByGroupEpoch(E2eeGroupEpoch $epoch): array
    {
        return $this->findBy(['groupEpoch' => $epoch]);
    }
}
