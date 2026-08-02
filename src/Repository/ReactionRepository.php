<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Community;
use App\Entity\Message;
use App\Entity\Reaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reaction>
 */
class ReactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reaction::class);
    }

    public function findByMessageAndUser(Message $message, User $user, string $emoji): ?Reaction
    {
        return $this->findOneBy([
            'message' => $message,
            'createdBy' => $user,
            'emoji' => $emoji,
        ]);
    }

    /**
     * @return array<string, list<array{id: int, userId: int}>>|null
     */
    public function buildCache(Message $message): ?array
    {
        // id ASC — the client renders reaction pills in this key order.
        $reactions = $this->findBy(['message' => $message], ['id' => 'ASC']);

        if (empty($reactions)) {
            return null;
        }

        $cache = [];
        foreach ($reactions as $reaction) {
            $userId = $reaction->getCreatedBy()?->getId();
            if (null !== $userId) {
                $cache[$reaction->getEmoji()][] = [
                    'id' => $reaction->getId(),
                    'userId' => $userId,
                ];
            }
        }

        return $cache ?: null;
    }

    /**
     * @param string[] $emojis
     *
     * @return Message[]
     */
    public function findMessagesWithReactions(Community $community, array $emojis): array
    {
        if ([] === $emojis) {
            return [];
        }

        return $this->getEntityManager()
            ->createQuery(
                'SELECT DISTINCT m
                 FROM App\Entity\Message m
                 JOIN m.page cp
                 JOIN cp.channel c
                 JOIN App\Entity\Reaction r ON r.message = m
                 WHERE c.community = :community AND r.emoji IN (:emojis)'
            )
            ->setParameter('community', $community)
            ->setParameter('emojis', $emojis)
            ->getResult();
    }

    /**
     * @param string[] $emojis
     */
    public function deleteByCommunityAndEmojis(Community $community, array $emojis): int
    {
        if ([] === $emojis) {
            return 0;
        }

        $em = $this->getEntityManager();

        // DQL DELETE cannot join across tables on MySQL — hence the two-step sub-select.
        $ids = $em->createQuery(
            'SELECT r.id
             FROM App\Entity\Reaction r
             JOIN r.message m
             JOIN m.page cp
             JOIN cp.channel c
             WHERE c.community = :community AND r.emoji IN (:emojis)'
        )
            ->setParameter('community', $community)
            ->setParameter('emojis', $emojis)
            ->getArrayResult();

        if ([] === $ids) {
            return 0;
        }
        $idList = array_column($ids, 'id');

        return (int) $em->createQuery('DELETE FROM App\Entity\Reaction r WHERE r.id IN (:ids)')
            ->setParameter('ids', $idList)
            ->execute();
    }
}
