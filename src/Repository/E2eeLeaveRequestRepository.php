<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\E2eeLeaveRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<E2eeLeaveRequest>
 */
class E2eeLeaveRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, E2eeLeaveRequest::class);
    }

    public function findForConversation(string $leaveRequestId, Conversation $conversation): ?E2eeLeaveRequest
    {
        return $this->findOneBy(['leaveRequestId' => $leaveRequestId, 'conversation' => $conversation]);
    }

    /** @return list<E2eeLeaveRequest> oldest first */
    public function findPending(Conversation $conversation): array
    {
        /** @var list<E2eeLeaveRequest> $requests */
        $requests = $this->createQueryBuilder('r')
            ->innerJoin('r.device', 'd')
            ->addSelect('d')
            ->where('r.conversation = :conversation')
            ->andWhere('r.fulfilledAt IS NULL')
            ->setParameter('conversation', $conversation)
            ->orderBy('r.createdAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $requests;
    }
}
