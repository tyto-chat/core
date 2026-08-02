<?php

declare(strict_types=1);

namespace App\Service\Webhook;

use App\Async\DispatchWebhookMessage;
use App\Dto\Notification\CreateNotificationDto;
use App\Dto\Webhook\WebhookEventContext;
use App\Entity\Webhook;
use App\Entity\WebhookDelivery;
use App\Enum\Notification\NotificationType;
use App\Enum\Webhook\WebhookDeliveryStatus;
use App\Exception\Webhook\InvalidTriggerKeyException;
use App\Exception\Webhook\MissingRequiredFilterException;
use App\Exception\Webhook\WebhookNotFoundException;
use App\Repository\WebhookDeliveryRepository;
use App\Security\SecurityContext;
use App\Service\AbstractDoctrineService;
use App\Service\Notification\NotificationServiceInterface;
use App\Service\User\UserServiceInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class WebhookService extends AbstractDoctrineService implements WebhookServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly WebhookDeliveryRepository $deliveryRepository,
        private readonly WebhookSigner $signer,
        private readonly WebhookTriggerRegistry $registry,
        private readonly MessageBusInterface $bus,
        private readonly UserServiceInterface $userService,
        private readonly NotificationServiceInterface $notificationService,
    ) {
    }

    /** Deliberately not admin-gated — the messenger worker runs token-less. */
    #[\Override]
    public function disableAfterExhaustedDelivery(int $deliveryId): void
    {
        $delivery = $this->deliveryRepository->find($deliveryId);
        if (null === $delivery) {
            return;
        }

        $delivery->setStatus(WebhookDeliveryStatus::Failed);

        $webhook = $delivery->getWebhook();
        $webhook->setIsActive(false);
        $webhook->setDisabledReason('Delivery failed after retries');

        $this->flush();

        foreach ($this->userService->getAdmins() as $admin) {
            $this->notificationService->new(new CreateNotificationDto(
                recipient: $admin,
                type: NotificationType::WebhookFailed,
                authorName: $webhook->getName(),
                reason: sprintf(
                    'Webhook "%s" (trigger: %s) has been disabled after repeated delivery failures.',
                    $webhook->getName(),
                    $webhook->getTriggerKey(),
                ),
            ));
        }
    }

    #[\Override]
    public function getById(int $id): Webhook
    {
        $this->security->throwAccessDeniedUnlessAdmin('Webhook management requires server-admin privileges.');

        $webhook = $this->entityManager->find(Webhook::class, $id);
        if (null === $webhook) {
            throw new WebhookNotFoundException(sprintf('Webhook %d not found.', $id));
        }

        return $webhook;
    }

    /**
     * @param array<string,mixed>|null $filters
     */
    private function assertRequiredFilters(string $triggerKey, ?array $filters): void
    {
        $trigger = $this->registry->get($triggerKey);
        if (null === $trigger) {
            return;
        }

        foreach ($trigger->getRequiredFilterFields() as $field) {
            $value = $filters[$field] ?? null;
            if (null === $value || '' === $value) {
                throw new MissingRequiredFilterException(sprintf('Trigger "%s" requires the filter "%s".', $triggerKey, $field));
            }
        }
    }

    /**
     * @param array<string,mixed>|null $filters
     *
     * @return array{webhook: Webhook, secret: string}
     */
    #[\Override]
    public function create(string $name, string $url, string $triggerKey, ?array $filters): array
    {
        $this->security->throwAccessDeniedUnlessAdmin('Webhook management requires server-admin privileges.');

        if (!$this->registry->has($triggerKey)) {
            throw new InvalidTriggerKeyException(sprintf('Trigger key "%s" is not registered.', $triggerKey));
        }

        $this->assertRequiredFilters($triggerKey, $filters);

        $secret = $this->signer->generateSecret();

        $webhook = new Webhook();
        $webhook->setName($name);
        $webhook->setUrl($url);
        $webhook->setTriggerKey($triggerKey);
        $webhook->setFilters($filters);
        $webhook->setSecret($secret);
        $webhook->setIsActive(true);
        $webhook->setCreatedBy($this->security->getUser());

        $this->persist($webhook);
        $this->flush();

        return ['webhook' => $webhook, 'secret' => $secret];
    }

    /**
     * @param array<string,mixed> $changes
     */
    #[\Override]
    public function update(Webhook $webhook, array $changes, bool $replayPending): Webhook
    {
        $this->security->throwAccessDeniedUnlessAdmin('Webhook management requires server-admin privileges.');

        $wasActive = $webhook->isActive();

        if (array_key_exists('name', $changes)) {
            $webhook->setName((string) $changes['name']);
        }
        if (array_key_exists('url', $changes)) {
            $webhook->setUrl((string) $changes['url']);
        }
        if (array_key_exists('filters', $changes)) {
            /** @var array<string,mixed>|null $filters */
            $filters = $changes['filters'];
            $this->assertRequiredFilters($webhook->getTriggerKey(), $filters);
            $webhook->setFilters($filters);
        }
        if (array_key_exists('isActive', $changes)) {
            $isActive = (bool) $changes['isActive'];
            $webhook->setIsActive($isActive);

            if ($isActive && !$wasActive) {
                $webhook->setDisabledReason(null);
            }
        }

        $webhook->setUpdatedAt(new \DateTimeImmutable());
        $this->flush();

        if ($replayPending && !$wasActive && $webhook->isActive()) {
            $this->replay($webhook);
        }

        return $webhook;
    }

    #[\Override]
    public function delete(Webhook $webhook): void
    {
        $this->security->throwAccessDeniedUnlessAdmin('Webhook management requires server-admin privileges.');

        $this->removeAndFlush($webhook);
    }

    #[\Override]
    public function regenerateSecret(Webhook $webhook): string
    {
        $this->security->throwAccessDeniedUnlessAdmin('Webhook management requires server-admin privileges.');

        $secret = $this->signer->generateSecret();
        $webhook->setSecret($secret);
        $webhook->setUpdatedAt(new \DateTimeImmutable());
        $this->flush();

        return $secret;
    }

    #[\Override]
    public function replay(Webhook $webhook): int
    {
        $this->security->throwAccessDeniedUnlessAdmin('Webhook management requires server-admin privileges.');

        $deliveries = $this->deliveryRepository->findReplayableFor($webhook);
        $ids = [];

        foreach ($deliveries as $delivery) {
            $delivery->setStatus(WebhookDeliveryStatus::Pending);
            $delivery->setAttempts(0);
            $delivery->setError(null);
            $ids[] = (int) $delivery->getId();
        }

        $this->flush();

        foreach ($ids as $id) {
            $this->bus->dispatch(new DispatchWebhookMessage($id));
        }

        return count($ids);
    }

    /**
     * @return array{deliveryId: int, status: string}
     */
    public function sendTest(Webhook $webhook): array
    {
        $this->security->throwAccessDeniedUnlessAdmin('Webhook management requires server-admin privileges.');

        $triggerKey = $webhook->getTriggerKey();
        $trigger = $this->registry->get($triggerKey);
        if (null === $trigger) {
            throw new InvalidTriggerKeyException(sprintf('Trigger "%s" is no longer registered; cannot send a test.', $triggerKey));
        }

        $ctx = new WebhookEventContext(
            actor: null,
            data: [
                'messageId' => '00000000-0000-0000-0000-000000000000',
                'messageText' => 'Test delivery from admin panel.',
                'authorId' => 0,
                'authorName' => 'tyto-test',
                'communityId' => 0,
                'channelId' => 0,
                'createdAt' => time(),
                'rootMessageId' => '00000000-0000-0000-0000-000000000000',
                'emoji' => '👍',
                'reactorId' => 0,
                'actionType' => 'warn',
                'targetUserId' => 0,
                'moderatorId' => 0,
                'reason' => 'test',
            ],
        );

        $delivery = new WebhookDelivery(
            $webhook,
            $triggerKey,
            null,
            $trigger->buildPayload($ctx),
            WebhookDeliveryStatus::Pending,
        );

        $this->persist($delivery);
        $this->flush();

        $this->bus->dispatch(new DispatchWebhookMessage((int) $delivery->getId()));

        return [
            'deliveryId' => (int) $delivery->getId(),
            'status' => $delivery->getStatus()->value,
        ];
    }
}
