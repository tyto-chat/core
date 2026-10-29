<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    public function findOneByIdentifier(string $identifier): ?Conversation
    {
        return $this->findOneBy(['identifier' => $identifier]);
    }

    /** @return int[] */
    public function findEncryptedIds(): array
    {
        return array_map('intval', $this->createQueryBuilder('c')
            ->select('c.id')
            ->where('c.encrypted = true')
            ->getQuery()
            ->getSingleColumnResult());
    }

    /** @return list<Conversation> */
    public function findEncryptedForUser(User $user): array
    {
        /** @var list<Conversation> $conversations */
        $conversations = $this->createQueryBuilder('c')
            ->innerJoin('c.members', 'm')
            ->where('c.encrypted = true')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $conversations;
    }

    public function findOneByParticipantsHash(string $hash): ?Conversation
    {
        return $this->findOneBy(['participantsHash' => $hash]);
    }
}
