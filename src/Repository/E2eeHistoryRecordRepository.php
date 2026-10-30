<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\E2eeAccount;
use App\Entity\E2eeDevice;
use App\Entity\E2eeHistoryRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<E2eeHistoryRecord>
 */
class E2eeHistoryRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, E2eeHistoryRecord::class);
    }

    public function head(E2eeDevice $device): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COALESCE(MAX(r.counter), 0)')
            ->where('r.device = :device')
            ->setParameter('device', $device)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneAt(E2eeDevice $device, int $counter): ?E2eeHistoryRecord
    {
        return $this->findOneBy(['device' => $device, 'counter' => $counter]);
    }

    /** @return list<E2eeHistoryRecord> ordered by device id, then counter */
    public function findPage(E2eeAccount $account, ?string $afterDeviceId, int $afterCounter, int $limit): array
    {
        $qb = $this->createQueryBuilder('r')
            ->join('r.device', 'd')
            ->addSelect('d')
            ->where('r.account = :account')
            ->setParameter('account', $account)
            ->orderBy('d.id', 'ASC')
            ->addOrderBy('r.counter', 'ASC')
            ->setMaxResults($limit);
        if (null !== $afterDeviceId) {
            $qb->andWhere('d.id > :afterDevice OR (d.id = :afterDevice AND r.counter > :afterCounter)')
                ->setParameter('afterDevice', $afterDeviceId)
                ->setParameter('afterCounter', $afterCounter);
        }

        /** @var list<E2eeHistoryRecord> $records */
        $records = $qb->getQuery()->getResult();

        return $records;
    }

    /** @return array<string, int> */
    public function heads(E2eeAccount $account): array
    {
        /** @var list<array{deviceId: string, head: int|string}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.device) AS deviceId', 'MAX(r.counter) AS head')
            ->where('r.account = :account')
            ->setParameter('account', $account)
            ->groupBy('r.device')
            ->orderBy('deviceId', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $heads = [];
        foreach ($rows as $row) {
            $heads[(string) $row['deviceId']] = (int) $row['head'];
        }

        return $heads;
    }

    public function deleteForAccount(E2eeAccount $account): void
    {
        $this->createQueryBuilder('r')
            ->delete()
            ->where('r.account = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->execute();
    }
}
