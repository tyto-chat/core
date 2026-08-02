<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Async\DispatchWebhookMessage;
use App\Service\Webhook\WebhookServiceInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

#[AsEventListener(event: WorkerMessageFailedEvent::class)]
final readonly class WebhookFailureListener
{
    public function __construct(
        private WebhookServiceInterface $webhookService,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof DispatchWebhookMessage) {
            return;
        }

        $this->webhookService->disableAfterExhaustedDelivery($message->deliveryId);
    }
}
