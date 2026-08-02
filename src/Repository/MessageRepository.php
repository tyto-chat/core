<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\Conversation;
use App\Entity\ConversationMember;
use App\Entity\Message;
use App\Entity\MessagePage;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    public function countForCommunity(Community $community): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->innerJoin('m.page', 'p')
            ->innerJoin('p.channel', 'ch')
            ->where('ch.community = :community')
            ->andWhere('m.deleted = false')
            ->setParameter('community', $community)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countNewInConversationFor(
        Conversation $conversation,
        User $excludingUser,
        ?\DateTimeImmutable $since,
    ): int {
        $qb = $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->innerJoin('m.page', 'p')
            ->where('p.conversation = :conversation')
            ->andWhere('m.deleted = false')
            ->andWhere('m.createdBy != :user')
            ->setParameter('conversation', $conversation)
            ->setParameter('user', $excludingUser);

        if (null !== $since) {
            $qb->andWhere('m.createdAt > :since')->setParameter('since', $since);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return array<int, int> conversation id → unread count */
    public function countUnreadPerConversationFor(User $user): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(p.conversation) AS cid', 'COUNT(m.id) AS unread')
            ->innerJoin('m.page', 'p')
            ->innerJoin(ConversationMember::class, 'cm', 'WITH', 'cm.conversation = p.conversation AND cm.user = :user')
            ->where('m.deleted = false')
            ->andWhere('m.createdBy != :user')
            ->andWhere('cm.lastReadAt IS NULL OR m.createdAt > cm.lastReadAt')
            ->groupBy('cid')
            ->setParameter('user', $user)
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['cid']] = (int) $row['unread'];
        }

        return $counts;
    }

    /** @return Message[] */
    public function findPinnedForChannel(Channel $channel): array
    {
        return $this->createQueryBuilder('m')
            ->innerJoin('m.page', 'p')
            ->where('p.channel = :channel')
            ->andWhere('m.pinnedAt IS NOT NULL')
            ->andWhere('m.deleted = false')
            ->andWhere('m.parent IS NULL')
            ->setParameter('channel', $channel)
            ->orderBy('m.pinnedAt', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();
    }

    public function countPinnedForChannel(Channel $channel): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->innerJoin('m.page', 'p')
            ->where('p.channel = :channel')
            ->andWhere('m.pinnedAt IS NOT NULL')
            ->andWhere('m.deleted = false')
            ->andWhere('m.parent IS NULL')
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Single chokepoint hiding thread replies from every channel and conversation timeline read.
     *
     * @return Message[]
     */
    public function findRootsByPage(MessagePage $page): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.revisions', 'b')->addSelect('b')
            ->where('m.page = :page')
            ->andWhere('m.parent IS NULL')
            ->setParameter('page', $page)
            ->orderBy('m.createdAt', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countNotDeleted(): int
    {
        return $this->count(['deleted' => false]);
    }

    /** @return Message[] */
    public function findNotDeletedBatch(int $offset, int $limit): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.deleted = false')
            ->orderBy('m.createdAt', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countRootsInPage(MessagePage $page): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.page = :page')
            ->andWhere('m.parent IS NULL')
            ->setParameter('page', $page)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The attachment join is what terminates the purge batch loop: once a message's
     * attachments are deleted it stops matching.
     *
     * @return Message[]
     */
    public function findWithAttachmentsOlderThanForPurge(\DateTimeImmutable $cutoff, int $limit): array
    {
        return $this->createQueryBuilder('m')
            ->distinct()
            ->join('m.attachments', 'a')
            ->where('m.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->orderBy('m.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return Message[] */
    public function findOlderThanForPurge(\DateTimeImmutable $cutoff, int $limit): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.createdAt < :cutoff')
            ->andWhere('m.deleted = false')
            ->setParameter('cutoff', $cutoff)
            ->orderBy('m.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The revision-text predicate (not isDeleted) is what terminates the purge batch loop.
     *
     * @return Message[]
     */
    public function findDeletedWithBodiesForPurge(\DateTimeImmutable $cutoff, int $limit): array
    {
        return $this->createQueryBuilder('m')
            ->distinct()
            ->join('m.revisions', 'b')
            ->where('m.createdAt < :cutoff')
            ->andWhere('m.deleted = true')
            ->andWhere("b.text <> ''")
            ->setParameter('cutoff', $cutoff)
            ->orderBy('m.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Soft-deleted replies deliberately included — the client renders tombstones in place.
     *
     * @return Message[]
     */
    public function findChildren(Message $root, int $limit = 50, ?Message $before = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->where('m.parent = :root')
            ->setParameter('root', $root)
            ->orderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limit);

        if (null !== $before) {
            $qb->andWhere('m.createdAt < :beforeCreated OR (m.createdAt = :beforeCreated AND m.id < :beforeId)')
                ->setParameter('beforeCreated', $before->getCreatedAt())
                ->setParameter('beforeId', $before->getId());
        }

        return array_reverse($qb->getQuery()->getResult());
    }
}
