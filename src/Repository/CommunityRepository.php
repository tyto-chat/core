<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Community;
use App\Entity\CommunityMember;
use App\Entity\MediaObject;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Community>
 */
class CommunityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Community::class);
    }

    public function findByLogo(MediaObject $logo): ?Community
    {
        return $this->findOneBy(['logo' => $logo]);
    }

    /** @return Community[] */
    public function findPublic(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.private = false')
            ->getQuery()
            ->getResult();
    }

    /** @return array<Community> */
    public function findPublicOrJoined(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin(CommunityMember::class, 'cm', 'WITH', 'cm.community = c AND cm.user = :user')
            ->where('c.private = false')
            ->orWhere('cm.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.createdAt', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /** @return array<array{id: int, identifier: string, name: string, channelCount: int, memberCount: int, messageCount: int, attachmentCount: int, attachmentsSize: int}> */
    public function findWithStats(): array
    {
        $em = $this->getEntityManager();

        // Members/channels joined separately from messages — one query would Cartesian-inflate SUM(size).
        /** @var array<array<string, mixed>> $baseRows */
        $baseRows = $em->createQuery('
                SELECT
                    c.id,
                    c.identifier,
                    c.name,
                    COUNT(DISTINCT ch.id) AS channelCount,
                    COUNT(DISTINCT cm.id) AS memberCount
                FROM App\Entity\Community c
                LEFT JOIN App\Entity\Channel ch ON ch.community = c
                LEFT JOIN App\Entity\CommunityMember cm ON cm.community = c
                GROUP BY c.id, c.identifier, c.name
                ORDER BY c.id ASC
            ')
            ->getScalarResult();

        /** @var array<array<string, mixed>> $mediaRows */
        $mediaRows = $em->createQuery('
                SELECT
                    IDENTITY(ch.community) AS communityId,
                    COUNT(DISTINCT m.id) AS messageCount,
                    COUNT(DISTINCT mo.id) AS attachmentCount,
                    COALESCE(SUM(mo.size), 0) AS attachmentsSize
                FROM App\Entity\Channel ch
                LEFT JOIN App\Entity\MessagePage cp ON cp.channel = ch
                LEFT JOIN App\Entity\Message m ON m.page = cp AND m.deleted = false
                LEFT JOIN App\Entity\MediaObject mo ON mo.message = m
                GROUP BY ch.community
            ')
            ->getScalarResult();

        /** @var array<int, array<string, mixed>> $mediaIndex */
        $mediaIndex = [];
        foreach ($mediaRows as $row) {
            $mediaIndex[(int) $row['communityId']] = $row;
        }

        return array_map(static function (array $row) use ($mediaIndex): array {
            $id = (int) $row['id'];
            $media = $mediaIndex[$id] ?? [];

            return [
                'id' => $id,
                'identifier' => (string) $row['identifier'],
                'name' => (string) $row['name'],
                'channelCount' => (int) $row['channelCount'],
                'memberCount' => (int) $row['memberCount'],
                'messageCount' => (int) ($media['messageCount'] ?? 0),
                'attachmentCount' => (int) ($media['attachmentCount'] ?? 0),
                'attachmentsSize' => (int) ($media['attachmentsSize'] ?? 0),
            ];
        }, $baseRows);
    }

    /**
     * @return array{rows: list<Community>, total: int}
     */
    public function findForAdminList(
        ?string $search,
        int $page,
        int $perPage,
        string $sortBy = 'createdAt',
        string $sortDir = 'DESC',
    ): array {
        $qb = $this->createQueryBuilder('c');

        if (null !== $search && '' !== trim($search)) {
            $qb->andWhere('c.name LIKE :term OR c.identifier LIKE :term')
                ->setParameter('term', '%'.addcslashes(trim($search), '%_\\').'%');
        }

        $countQb = (clone $qb)->select('COUNT(c.id)');
        /** @var int $total */
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $sortColumns = [
            'name' => 'c.name',
            'createdAt' => 'c.createdAt',
        ];
        $dir = 'ASC' === strtoupper($sortDir) ? Order::Ascending->value : Order::Descending->value;
        $qb->orderBy($sortColumns[$sortBy] ?? 'c.createdAt', $dir)
            ->addOrderBy('c.id', Order::Descending->value)
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage);

        /** @var list<Community> $rows */
        $rows = $qb->getQuery()->getResult();

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<Community> */
    public function findJoined(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin(CommunityMember::class, 'cm', 'WITH', 'cm.community = c AND cm.user = :user')
            ->where('cm.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.createdAt', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }
}
