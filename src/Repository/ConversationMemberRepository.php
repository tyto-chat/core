<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\ConversationMember;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConversationMember>
 */
class ConversationMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConversationMember::class);
    }

    public function findForUser(Conversation $conversation, User $user): ?ConversationMember
    {
        return $this->findOneBy([
            'conversation' => $conversation,
            'user' => $user,
        ]);
    }

    public function markAllReadForUser(User $user, \DateTimeImmutable $when): void
    {
        $this->createQueryBuilder('cm')
            ->update()
            ->set('cm.lastReadAt', ':when')
            ->where('cm.user = :user')
            ->setParameter('user', $user)
            ->setParameter('when', $when)
            ->getQuery()
            ->execute();
    }

    /** @return Conversation[] caller's conversations, newest activity first */
    public function findConversationsForUser(User $user): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('c')
            ->from(Conversation::class, 'c')
            ->innerJoin('c.members', 'cm')
            ->where('cm.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.lastMessageAt', 'DESC')
            ->addOrderBy('c.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
