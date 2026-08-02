<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\ChannelSection;
use App\Entity\Community;
use App\Entity\CommunityMember;
use App\Entity\User;
use App\Enum\Channel\ChannelType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Channel>
 */
class ChannelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Channel::class);
    }

    /** @return Channel[] */
    public function findAllWithCommunity(): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('com')
            ->join('c.community', 'com')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Channel[]
     */
    public function findCandidateViewableForUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('com')
            ->join('c.community', 'com')
            ->leftJoin(CommunityMember::class, 'cm', 'WITH', 'cm.community = com AND cm.user = :user')
            ->where('cm.id IS NOT NULL')
            ->orWhere('c.private = false AND com.private = false')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }

    /** @return Channel[] all channels of one community, community fetch-joined */
    public function findByCommunityWithCommunity(Community $community): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('com')
            ->join('c.community', 'com')
            ->where('c.community = :community')
            ->setParameter('community', $community)
            ->getQuery()
            ->getResult();
    }

    /** @return Channel[] — non-private text channels in public communities, visible to anonymous users */
    public function findPublicTextChannels(): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('com')
            ->join('c.community', 'com')
            ->where('c.private = false')
            ->andWhere('com.private = false')
            ->andWhere('c.type != :audio')
            ->setParameter('audio', ChannelType::Audio->value)
            ->getQuery()
            ->getResult();
    }

    public function findMaxPositionInSection(ChannelSection $section): int
    {
        $max = $this->createQueryBuilder('c')
            ->select('MAX(c.position)')
            ->andWhere('c.section = :s')->setParameter('s', $section)
            ->getQuery()->getSingleScalarResult();

        return null === $max ? -1 : (int) $max;
    }

    /** @return Channel[] */
    public function findArchivedBefore(\DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.archivedAt IS NOT NULL')
            ->andWhere('c.archivedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }

    /** @return array<int, int> community id → count of public, non-archived channels */
    public function countPublicActivePerCommunity(): array
    {
        /** @var list<array{communityId: int, channelCount: int}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.community) AS communityId', 'COUNT(c.id) AS channelCount')
            ->andWhere('c.private = false')
            ->andWhere('c.archivedAt IS NULL')
            ->andWhere('c.community IS NOT NULL')
            ->groupBy('c.community')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['communityId']] = (int) $row['channelCount'];
        }

        return $counts;
    }
}
