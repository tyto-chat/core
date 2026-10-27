<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\E2eeDevice;
use App\Entity\E2eeKeyPackage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<E2eeKeyPackage>
 */
class E2eeKeyPackageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, E2eeKeyPackage::class);
    }

    public function countUsableOneTime(E2eeDevice $device, \DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.device = :device')
            ->andWhere('p.lastResort = false')
            ->andWhere('p.claimedAt IS NULL')
            ->andWhere('p.expiresAt > :now')
            ->setParameter('device', $device)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function deleteExpired(E2eeDevice $device, \DateTimeImmutable $now): void
    {
        $this->createQueryBuilder('p')
            ->delete()
            ->where('p.device = :device')
            ->andWhere('p.claimedAt IS NULL')
            ->andWhere('p.lastResort = false')
            ->andWhere('p.expiresAt <= :now')
            ->setParameter('device', $device)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->execute();
    }

    /**
     * @param list<string> $fingerprints
     *
     * @return list<string> the given fingerprints already stored for the device, claimed or not
     */
    public function findExistingFingerprints(E2eeDevice $device, array $fingerprints): array
    {
        if ([] === $fingerprints) {
            return [];
        }

        /** @var list<string> $existing */
        $existing = $this->createQueryBuilder('p')
            ->select('p.fingerprint')
            ->where('p.device = :device')
            ->andWhere('p.fingerprint IN (:fingerprints)')
            ->setParameter('device', $device)
            ->setParameter('fingerprints', $fingerprints)
            ->getQuery()
            ->getSingleColumnResult();

        return $existing;
    }

    public function retireLastResort(E2eeDevice $device, \DateTimeImmutable $now): void
    {
        $this->createQueryBuilder('p')
            ->update()
            ->set('p.claimedAt', ':now')
            ->set('p.data', ':empty')
            ->where('p.device = :device')
            ->andWhere('p.lastResort = true')
            ->andWhere('p.claimedAt IS NULL')
            ->setParameter('device', $device)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('empty', '')
            ->getQuery()
            ->execute();
    }

    public function deleteAllForDevice(E2eeDevice $device): void
    {
        $this->createQueryBuilder('p')
            ->delete()
            ->where('p.device = :device')
            ->setParameter('device', $device)
            ->getQuery()
            ->execute();
    }

    public function claimOneTime(E2eeDevice $device, \DateTimeImmutable $now): ?string
    {
        $connection = $this->getEntityManager()->getConnection();
        $row = $connection->fetchAssociative(
            'SELECT id, data FROM e2ee_key_package
             WHERE device_id = :device AND last_resort = 0 AND claimed_at IS NULL AND expires_at > :now
             ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED',
            ['device' => $device->getId(), 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
        if (false === $row) {
            return null;
        }

        $connection->executeStatement(
            'UPDATE e2ee_key_package SET claimed_at = :now, data = \'\' WHERE id = :id',
            ['now' => $now, 'id' => $row['id']],
            ['now' => Types::DATETIME_IMMUTABLE],
        );

        return (string) $row['data'];
    }

    public function findUsableLastResort(E2eeDevice $device, \DateTimeImmutable $now): ?string
    {
        $data = $this->createQueryBuilder('p')
            ->select('p.data')
            ->where('p.device = :device')
            ->andWhere('p.lastResort = true')
            ->andWhere('p.claimedAt IS NULL')
            ->andWhere('p.expiresAt > :now')
            ->setParameter('device', $device)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return \is_array($data) ? (string) $data['data'] : null;
    }

    public function findLatestLastResortFingerprint(E2eeDevice $device): ?string
    {
        $row = $this->createQueryBuilder('p')
            ->select('p.fingerprint')
            ->where('p.device = :device')
            ->andWhere('p.lastResort = true')
            ->setParameter('device', $device)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return \is_array($row) ? (string) $row['fingerprint'] : null;
    }

    public function hasUsable(E2eeDevice $device, \DateTimeImmutable $now): bool
    {
        return null !== $this->createQueryBuilder('p')
            ->select('p.id')
            ->where('p.device = :device')
            ->andWhere('p.claimedAt IS NULL')
            ->andWhere('p.expiresAt > :now')
            ->setParameter('device', $device)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
