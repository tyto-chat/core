<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\Notification\NotificationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    private const int LIST_LIMIT = 100;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /** @return Notification[] */
    public function findByRecipientAndCommunity(User $user, Community $community): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.recipient = :user AND n.community = :community')
            ->setParameter('user', $user)
            ->setParameter('community', $community)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults(self::LIST_LIMIT)
            ->getQuery()
            ->getResult();
    }

    /** @return array<string, int> communityId (string) → unread count; 'dm' → DM unread count */
    public function countUnreadByRecipient(User $user): array
    {
        $rows = $this->createQueryBuilder('n')
            ->select('IDENTITY(n.community) AS communityId, COUNT(n.id) AS cnt')
            ->where('n.recipient = :user AND n.isRead = false')
            ->setParameter('user', $user)
            ->groupBy('n.community')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($rows as $row) {
            $key = null === $row['communityId'] ? 'dm' : (string) $row['communityId'];
            $result[$key] = (int) $row['cnt'];
        }

        return $result;
    }

    public function markAllReadForCommunity(User $user, Community $community): void
    {
        $this->createQueryBuilder('n')
            ->update()
            ->set('n.isRead', 'true')
            ->set('n.coalesceKey', 'NULL')
            ->where('n.recipient = :user AND n.community = :community AND n.isRead = false')
            ->setParameter('user', $user)
            ->setParameter('community', $community)
            ->getQuery()
            ->execute();
    }

    /** @return Notification[] DM (community is null) notifications for caller, newest first */
    public function findDmByRecipient(User $user): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.recipient = :user AND n.community IS NULL')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults(self::LIST_LIMIT)
            ->getQuery()
            ->getResult();
    }

    public function markAllDmReadForUser(User $user): void
    {
        $this->createQueryBuilder('n')
            ->update()
            ->set('n.isRead', 'true')
            ->set('n.coalesceKey', 'NULL')
            ->where('n.recipient = :user AND n.community IS NULL AND n.isRead = false')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    public function markAllReadForUser(User $user): void
    {
        $this->createQueryBuilder('n')
            ->update()
            ->set('n.isRead', 'true')
            ->set('n.coalesceKey', 'NULL')
            ->where('n.recipient = :user AND n.isRead = false')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('n')
            ->delete()
            ->where('n.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }

    /**
     * @param list<NotificationType> $allowedTypes
     *
     * @return array<int, list<Notification>>
     */
    public function findDigestCandidates(array $allowedTypes): array
    {
        if ([] === $allowedTypes) {
            return [];
        }

        /** @var Notification[] $rows */
        $rows = $this->createQueryBuilder('n')
            ->join('n.recipient', 'u')
            ->addSelect('u')
            ->where('n.isRead = false')
            ->andWhere('n.emailedAt IS NULL')
            ->andWhere('n.type IN (:types)')
            ->andWhere('u.emailNotifications = true')
            ->setParameter('types', array_map(static fn (NotificationType $t): string => $t->value, $allowedTypes))
            ->orderBy('u.id', 'ASC')
            ->addOrderBy('n.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($rows as $row) {
            $userId = $row->getRecipient()?->getId();
            if (null !== $userId) {
                $grouped[$userId][] = $row;
            }
        }

        return $grouped;
    }

    public function findOpenChannelActivity(User $user, Channel $channel): ?Notification
    {
        // Oldest-first so racing upserts converge on one row — MariaDB has no partial indexes to enforce "one open row".
        return $this->createQueryBuilder('n')
            ->where('n.recipient = :user')
            ->andWhere('n.type = :type')
            ->andWhere('n.isRead = false')
            ->andWhere('n.communityIdentifier = :communityIdentifier')
            ->andWhere('n.channelIdentifier = :channelIdentifier')
            ->orderBy('n.id', 'ASC')
            ->setParameter('user', $user)
            ->setParameter('type', NotificationType::ChannelActivity->value)
            ->setParameter('communityIdentifier', $channel->getCommunity()?->getIdentifier() ?? '')
            ->setParameter('channelIdentifier', $channel->getIdentifier() ?? '')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
