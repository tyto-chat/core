<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Webhook;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Webhook>
 */
class WebhookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Webhook::class);
    }

    /**
     * All webhooks (active+inactive) for a trigger — emitter needs inactive ones too (queueing).
     *
     * @return Webhook[]
     */
    public function findByTriggerKey(string $triggerKey): array
    {
        return $this->findBy(['triggerKey' => $triggerKey]);
    }
}
