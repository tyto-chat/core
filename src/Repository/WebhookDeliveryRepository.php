<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Webhook;
use App\Entity\WebhookDelivery;
use App\Enum\Webhook\WebhookDeliveryStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WebhookDelivery>
 */
class WebhookDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebhookDelivery::class);
    }

    public function countQueuedFor(Webhook $webhook): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.webhook = :w')->setParameter('w', $webhook)
            ->andWhere('d.status = :s')->setParameter('s', WebhookDeliveryStatus::Queued)
            ->getQuery()->getSingleScalarResult();
    }

    public function countReplayableFor(Webhook $webhook): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.webhook = :w')->setParameter('w', $webhook)
            ->andWhere('d.status IN (:s)')->setParameter('s', [WebhookDeliveryStatus::Queued, WebhookDeliveryStatus::Failed])
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * @return WebhookDelivery[]
     */
    public function findReplayableFor(Webhook $webhook): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.webhook = :w')->setParameter('w', $webhook)
            ->andWhere('d.status IN (:s)')->setParameter('s', [WebhookDeliveryStatus::Queued, WebhookDeliveryStatus::Failed])
            ->orderBy('d.createdAt', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * @return WebhookDelivery[]
     */
    public function findForWebhookPaginated(Webhook $webhook, int $page, int $perPage): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.webhook = :w')->setParameter('w', $webhook)
            ->orderBy('d.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)
            ->getQuery()->getResult();
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('d')
            ->delete()->where('d.createdAt < :c')->setParameter('c', $cutoff)
            ->getQuery()->execute();
    }
}
