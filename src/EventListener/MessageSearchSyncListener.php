<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Async\IndexMessageDocumentMessage;
use App\Async\RemoveMessageDocumentMessage;
use App\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;

/** Only the message id crosses the bus — the handler re-resolves it; post-flush entities may be detached. */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
final readonly class MessageSearchSyncListener
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof Message) {
            $this->bus->dispatch(new IndexMessageDocumentMessage($entity->getId()));
        }
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof Message) {
            $this->bus->dispatch(new IndexMessageDocumentMessage($entity->getId()));
        }
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof Message) {
            $this->bus->dispatch(new RemoveMessageDocumentMessage($entity->getId()));
        }
    }
}
