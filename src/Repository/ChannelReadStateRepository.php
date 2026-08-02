<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\ChannelMember;
use App\Entity\ChannelReadState;
use App\Entity\ChannelUserPreference;
use App\Entity\Community;
use App\Entity\CommunityMember;
use App\Entity\Message;
use App\Entity\User;
use App\Enum\Channel\ChannelNotificationLevel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChannelReadState>
 */
class ChannelReadStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChannelReadState::class);
    }

    public function findForUser(Channel $channel, User $user): ?ChannelReadState
    {
        return $this->findOneBy(['channel' => $channel, 'user' => $user]);
    }

    /**
     * @param int[] $userIds
     *
     * @return array<int, \DateTimeImmutable> userId => lastReadAt
     */
    public function findLastReadAtByUserIds(Channel $channel, array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('rs')
            ->select('IDENTITY(rs.user) AS userId', 'rs.lastReadAt AS lastReadAt')
            ->where('rs.channel = :channel')
            ->andWhere('rs.user IN (:userIds)')
            ->setParameter('channel', $channel)
            ->setParameter('userIds', $userIds)
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['userId']] = $row['lastReadAt'];
        }

        return $map;
    }

    /**
     * @param Channel[] $channels
     */
    public function upsertManyToNow(User $user, array $channels): void
    {
        if ([] === $channels) {
            return;
        }

        $em = $this->getEntityManager();

        /** @var ChannelReadState[] $existing */
        $existing = $this->createQueryBuilder('rs')
            ->where('rs.user = :user AND rs.channel IN (:channels)')
            ->setParameter('user', $user)
            ->setParameter('channels', $channels)
            ->getQuery()
            ->getResult();

        /** @var array<int, ChannelReadState> $existingByChannelId */
        $existingByChannelId = [];
        foreach ($existing as $rs) {
            $cid = $rs->getChannel()->getId();
            if (null !== $cid) {
                $existingByChannelId[$cid] = $rs;
            }
        }

        $now = new \DateTimeImmutable();
        foreach ($channels as $channel) {
            $cid = $channel->getId();
            if (null === $cid) {
                continue;
            }
            $rs = $existingByChannelId[$cid] ?? null;
            if (null === $rs) {
                $rs = (new ChannelReadState())->setUser($user)->setChannel($channel);
                $em->persist($rs);
            }
            $rs->setLastReadAt($now);
        }
        $em->flush();
    }

    /**
     * @return string[]
     */
    public function findUnreadChannelIdentifiers(Community $community, User $user): array
    {
        $mutedMember = $this->getEntityManager()
            ->getRepository(CommunityMember::class)
            ->findOneBy(['user' => $user, 'community' => $community, 'notificationsMuted' => true]);
        if (null !== $mutedMember) {
            return [];
        }

        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT c.identifier')
            ->from(Message::class, 'm')
            ->innerJoin('m.page', 'p')
            ->innerJoin('p.channel', 'c')
            ->leftJoin(
                ChannelReadState::class,
                'rs',
                'WITH',
                'rs.channel = c AND rs.user = :user',
            )
            ->leftJoin(
                ChannelMember::class,
                'cm',
                'WITH',
                'cm.channel = c AND cm.user = :user',
            )
            ->leftJoin(
                ChannelUserPreference::class,
                'np',
                'WITH',
                'np.channel = c AND np.user = :user',
            )
            ->where('c.community = :community')
            ->andWhere('m.deleted = false')
            ->andWhere('m.parent IS NULL')
            ->andWhere('(m.createdBy IS NULL OR m.createdBy != :user)')
            ->andWhere('(rs.lastReadAt IS NULL OR m.createdAt > rs.lastReadAt)')
            ->andWhere('(c.private = false OR cm.id IS NOT NULL)')
            ->andWhere('(np.id IS NULL OR np.level != :levelNone)')
            ->setParameter('community', $community)
            ->setParameter('user', $user)
            ->setParameter('levelNone', ChannelNotificationLevel::None->value)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(strval(...), $rows);
    }
}
