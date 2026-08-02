<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\MessagePage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MessagePage>
 */
class MessagePageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessagePage::class);
    }

    public function findLatestForChannel(Channel $channel): ?MessagePage
    {
        return $this->createQueryBuilder('mp')
            ->where('mp.channel = :channel')
            ->setParameter('channel', $channel)
            ->orderBy('mp.pageNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestForConversation(Conversation $conversation): ?MessagePage
    {
        return $this->createQueryBuilder('mp')
            ->where('mp.conversation = :conversation')
            ->setParameter('conversation', $conversation)
            ->orderBy('mp.pageNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByChannelAndPageNumber(Channel $channel, int $pageNumber): ?MessagePage
    {
        return $this->findOneBy(['channel' => $channel, 'pageNumber' => $pageNumber]);
    }

    public function findByConversationAndPageNumber(Conversation $conversation, int $pageNumber): ?MessagePage
    {
        return $this->findOneBy(['conversation' => $conversation, 'pageNumber' => $pageNumber]);
    }

    /** @return MessagePage[] */
    public function findAllForChannel(Channel $channel): array
    {
        return $this->createQueryBuilder('mp')
            ->where('mp.channel = :channel')
            ->setParameter('channel', $channel)
            ->orderBy('mp.pageNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return MessagePage[] */
    public function findAllForConversation(Conversation $conversation): array
    {
        return $this->createQueryBuilder('mp')
            ->where('mp.conversation = :conversation')
            ->setParameter('conversation', $conversation)
            ->orderBy('mp.pageNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function nextPageNumberForChannel(Channel $channel): int
    {
        $max = $this->createQueryBuilder('mp')
            ->select('MAX(mp.pageNumber)')
            ->where('mp.channel = :channel')
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($max ?? 0) + 1;
    }

    public function nextPageNumberForConversation(Conversation $conversation): int
    {
        $max = $this->createQueryBuilder('mp')
            ->select('MAX(mp.pageNumber)')
            ->where('mp.conversation = :conversation')
            ->setParameter('conversation', $conversation)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($max ?? 0) + 1;
    }
}
