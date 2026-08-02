<?php

declare(strict_types=1);

namespace App\Service\Webhook;

use App\Async\DispatchWebhookMessage;
use App\Dto\Webhook\WebhookEventContext;
use App\Entity\WebhookDelivery;
use App\Enum\Webhook\WebhookDeliveryStatus;
use App\Repository\WebhookDeliveryRepository;
use App\Repository\WebhookRepository;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Messenger\MessageBusInterface;

final class WebhookEmitter implements WebhookEmitterInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly WebhookRepository $webhookRepository,
        private readonly WebhookDeliveryRepository $deliveryRepository,
        private readonly WebhookTriggerRegistry $registry,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly SettingsServiceInterface $settings,
    ) {
    }

    #[\Override]
    public function emit(string $triggerKey, WebhookEventContext $ctx): void
    {
        try {
            $trigger = $this->registry->get($triggerKey);
            if (null === $trigger) {
                return;
            }

            $pending = [];
            $persisted = [];
            foreach ($this->webhookRepository->findByTriggerKey($triggerKey) as $webhook) {
                if (!$trigger->matches($webhook->getFilters(), $ctx)) {
                    continue;
                }

                if (!$webhook->isActive()
                    && $this->deliveryRepository->countQueuedFor($webhook) >= $this->settings->get(Settings::webhookMaxQueued())
                ) {
                    continue;
                }

                $payload = $trigger->buildPayload($ctx);
                $status = $webhook->isActive()
                    ? WebhookDeliveryStatus::Pending
                    : WebhookDeliveryStatus::Queued;

                $delivery = new WebhookDelivery($webhook, $triggerKey, $ctx->actor, $payload, $status);
                $this->em->persist($delivery);
                $persisted[] = $delivery;
                if (WebhookDeliveryStatus::Pending === $status) {
                    $pending[] = $delivery;
                }
            }

            if ([] !== $persisted) {
                $this->em->flush();
            }
            foreach ($pending as $delivery) {
                $this->bus->dispatch(new DispatchWebhookMessage((int) $delivery->getId()));
            }
        } catch (\Throwable $e) {
            $this->logger?->error('WebhookEmitter::emit failed', [
                'triggerKey' => $triggerKey,
                'exception' => $e,
            ]);
        }
    }
}
