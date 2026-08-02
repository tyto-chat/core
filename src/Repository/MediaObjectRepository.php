<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\MediaObject;
use App\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MediaObject>
 */
class MediaObjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MediaObject::class);
    }

    public function findByFilePath(string $filePath): ?MediaObject
    {
        return $this->findOneBy(['filePath' => $filePath]);
    }

    /** @return MediaObject[] */
    public function findEligibleForDiskPurge(\DateTimeImmutable $maxCreatedAt, bool $includeDms, int $limit): array
    {
        $qb = $this->createQueryBuilder('mo')
            ->join('mo.message', 'm')
            ->join('m.page', 'p')
            ->where("mo.type = 'attachment'")
            ->andWhere('mo.createdAt < :cutoff')
            ->andWhere('(m.pinnedAt IS NULL OR m.deleted = true)')
            ->orderBy('m.deleted', 'DESC')
            ->addOrderBy('mo.createdAt', 'ASC')
            ->setParameter('cutoff', $maxCreatedAt)
            ->setMaxResults($limit);

        if (!$includeDms) {
            $qb->andWhere('p.channel IS NOT NULL');
        }

        return $qb->getQuery()->getResult();
    }

    public function getTotalSize(): int
    {
        $result = $this->createQueryBuilder('mo')
            ->select('COALESCE(SUM(mo.size), 0)')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    /** @return list<string> */
    public function findFilePathsForChannel(Channel $channel): array
    {
        $rows = $this->createQueryBuilder('mo')
            ->select('mo.filePath')
            ->join('mo.message', 'm')
            ->join('m.page', 'p')
            ->where('p.channel = :channel')
            ->andWhere('mo.filePath IS NOT NULL')
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(strval(...), $rows));
    }

    /** @return list<string> */
    public function findFilePathsForCommunity(Community $community): array
    {
        $rows = $this->createQueryBuilder('mo')
            ->select('mo.filePath')
            ->join('mo.message', 'm')
            ->join('m.page', 'p')
            ->join('p.channel', 'c')
            ->where('c.community = :community')
            ->andWhere('mo.filePath IS NOT NULL')
            ->setParameter('community', $community)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(strval(...), $rows));
    }

    /** @return list<string> */
    public function findFilePathsForMessage(Message $message): array
    {
        $rows = $this->createQueryBuilder('mo')
            ->select('mo.filePath')
            ->where('mo.message = :message')
            ->andWhere('mo.filePath IS NOT NULL')
            ->setParameter('message', $message)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(strval(...), $rows));
    }

    public function deleteRowsForMessage(Message $message): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('DELETE FROM App\Entity\MediaObject mo WHERE mo.message = :message')
            ->setParameter('message', $message)
            ->execute();
    }

    public function deleteRowsForChannel(Channel $channel): int
    {
        $ids = $this->createQueryBuilder('mo')
            ->select('mo.id')
            ->join('mo.message', 'm')
            ->join('m.page', 'p')
            ->where('p.channel = :channel')
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getSingleColumnResult();

        return $this->deleteByIds($ids);
    }

    public function deleteRowsForCommunity(Community $community): int
    {
        $ids = $this->createQueryBuilder('mo')
            ->select('mo.id')
            ->join('mo.message', 'm')
            ->join('m.page', 'p')
            ->join('p.channel', 'c')
            ->where('c.community = :community')
            ->setParameter('community', $community)
            ->getQuery()
            ->getSingleColumnResult();

        return $this->deleteByIds($ids);
    }

    /** @param array<mixed> $ids */
    private function deleteByIds(array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        return (int) $this->getEntityManager()
            ->createQuery('DELETE FROM App\Entity\MediaObject mo WHERE mo.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->execute();
    }
}
