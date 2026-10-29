<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\E2eeGroupEpoch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<E2eeGroupEpoch>
 */
class E2eeGroupEpochRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, E2eeGroupEpoch::class);
    }

    public function findLatest(Conversation $conversation): ?E2eeGroupEpoch
    {
        return $this->findOneBy(['conversation' => $conversation], ['epoch' => 'DESC']);
    }

    public function findOneByCommitId(string $commitId): ?E2eeGroupEpoch
    {
        return $this->findOneBy(['commitId' => $commitId]);
    }

    /** @return list<E2eeGroupEpoch> */
    public function findAfter(Conversation $conversation, int $after, int $limit): array
    {
        /** @var list<E2eeGroupEpoch> $epochs */
        $epochs = $this->createQueryBuilder('e')
            ->where('e.conversation = :conversation')
            ->andWhere('e.epoch > :after')
            ->setParameter('conversation', $conversation)
            ->setParameter('after', $after)
            ->orderBy('e.epoch', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $epochs;
    }
}
