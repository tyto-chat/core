<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Conversation;
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

    public function findOneByParticipantsHash(string $hash): ?Conversation
    {
        return $this->findOneBy(['participantsHash' => $hash]);
    }
}
