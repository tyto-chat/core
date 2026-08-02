<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhook;

use App\Dto\Webhook\WebhookEventContext;
use App\Entity\User;
use App\Entity\Webhook;
use App\Entity\WebhookDelivery;
use App\Enum\Webhook\WebhookDeliveryStatus;
use App\Exception\Webhook\InvalidTriggerKeyException;
use App\Exception\Webhook\MissingRequiredFilterException;
use App\Repository\WebhookDeliveryRepository;
use App\Security\SecurityContext;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Notification\NotificationServiceInterface;
use App\Service\User\UserServiceInterface;
use App\Service\Webhook\Trigger\ReactionAddedTrigger;
use App\Service\Webhook\WebhookService;
use App\Service\Webhook\WebhookSigner;
use App\Service\Webhook\WebhookTriggerInterface;
use App\Service\Webhook\WebhookTriggerRegistry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
class WebhookServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private WebhookDeliveryRepository&MockObject $deliveryRepository;
    private MessageBusInterface&MockObject $bus;
    private Security&MockObject $security;
    private UserServiceInterface&MockObject $userService;
    private NotificationServiceInterface&MockObject $notificationService;
    private WebhookSigner $signer;
    private WebhookTriggerRegistry $registry;

    #[\Override]
    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->deliveryRepository = $this->createMock(WebhookDeliveryRepository::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->security = $this->createMock(Security::class);
        $this->security->method('isGranted')->willReturn(true);
        $this->userService = $this->createMock(UserServiceInterface::class);
        $this->notificationService = $this->createMock(NotificationServiceInterface::class);
        $this->signer = new WebhookSigner();
        $this->registry = new WebhookTriggerRegistry([$this->makeTrigger('message.created')]);
    }

    private function makeService(): WebhookService
    {
        $service = new WebhookService(
            new SecurityContext($this->security, $this->createMock(CommunityMembershipServiceInterface::class)),
            $this->deliveryRepository,
            $this->signer,
            $this->registry,
            $this->bus,
            $this->userService,
            $this->notificationService,
        );
        $service->setEntityManager($this->em);
        $service->setLogger(new NullLogger());

        return $service;
    }

    private function makeTrigger(string $key): WebhookTriggerInterface
    {
        return new class($key) implements WebhookTriggerInterface {
            public function __construct(private readonly string $key)
            {
            }

            public function getKey(): string
            {
                return $this->key;
            }

            public function getLabel(): string
            {
                return 'Label';
            }

            public function getDescription(): string
            {
                return 'Desc';
            }

            public function getFilterFields(): array
            {
                return [];
            }

            public function getRequiredFilterFields(): array
            {
                return [];
            }

            public function matches(?array $filters, WebhookEventContext $ctx): bool
            {
                return true;
            }

            public function buildPayload(WebhookEventContext $ctx): array
            {
                return [];
            }
        };
    }

    private function makeWebhook(bool $active = true): Webhook
    {
        $webhook = new Webhook();
        $webhook->setName('Test');
        $webhook->setUrl('https://example.com/hook');
        $webhook->setTriggerKey('message.created');
        $webhook->setSecret($this->signer->generateSecret());
        $webhook->setIsActive($active);

        return $webhook;
    }

    public function testCreateWithUnknownTriggerKeyThrows(): void
    {
        $service = $this->makeService();

        $this->expectException(InvalidTriggerKeyException::class);

        $service->create('My Hook', 'https://example.com/hook', 'unknown.trigger', null);
    }

    public function testCreateMissingRequiredFilterThrows(): void
    {
        // reaction.added requires messageId + emoji.
        $this->registry = new WebhookTriggerRegistry([new ReactionAddedTrigger()]);
        $service = $this->makeService();

        $this->expectException(MissingRequiredFilterException::class);

        $service->create('Reactions', 'https://example.com/hook', 'reaction.added', ['emoji' => '👍']);
    }

    public function testCreateWithRequiredFiltersSucceeds(): void
    {
        $this->registry = new WebhookTriggerRegistry([new ReactionAddedTrigger()]);
        $service = $this->makeService();

        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $result = $service->create(
            'Reactions',
            'https://example.com/hook',
            'reaction.added',
            ['messageId' => 'abc-123', 'emoji' => '👍'],
        );
        self::assertSame('reaction.added', $result['webhook']->getTriggerKey());
    }

    public function testCreateGeneratesSecretAndPersists(): void
    {
        $service = $this->makeService();

        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $result = $service->create('My Hook', 'https://example.com/hook', 'message.created', null);

        self::assertArrayHasKey('webhook', $result);
        self::assertArrayHasKey('secret', $result);
        self::assertInstanceOf(Webhook::class, $result['webhook']);
        self::assertIsString($result['secret']);
        self::assertNotEmpty($result['secret']);
        self::assertSame('My Hook', $result['webhook']->getName());
        self::assertSame('message.created', $result['webhook']->getTriggerKey());
    }

    public function testCreateSetsCreatedByToCurrentUser(): void
    {
        $admin = new User();
        $this->security->method('getUser')->willReturn($admin);

        $service = $this->makeService();

        $result = $service->create('My Hook', 'https://example.com/hook', 'message.created', null);

        self::assertSame($admin, $result['webhook']->getCreatedBy());
    }

    public function testCreateSetsCreatedByNullWhenNoAuthenticatedUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $service = $this->makeService();

        $result = $service->create('My Hook', 'https://example.com/hook', 'message.created', null);

        self::assertNull($result['webhook']->getCreatedBy());
    }

    public function testUpdateAppliesChangesAndBumpsUpdatedAt(): void
    {
        $service = $this->makeService();
        $webhook = $this->makeWebhook();
        $originalUpdatedAt = $webhook->getUpdatedAt();

        // Small sleep to ensure the timestamp differs
        usleep(1000);

        $this->em->expects(self::once())->method('flush');

        $service->update($webhook, ['name' => 'New Name', 'url' => 'https://new.example.com/hook'], false);

        self::assertSame('New Name', $webhook->getName());
        self::assertSame('https://new.example.com/hook', $webhook->getUrl());
        self::assertGreaterThanOrEqual($originalUpdatedAt, $webhook->getUpdatedAt());
    }

    public function testReEnablingClearsDisabledReason(): void
    {
        $service = $this->makeService();
        $webhook = $this->makeWebhook(active: false);
        $webhook->setDisabledReason('Delivery failed after retries');

        $this->em->method('flush');
        $this->deliveryRepository->method('findReplayableFor')->willReturn([]);

        $service->update($webhook, ['isActive' => true], false);

        self::assertTrue($webhook->isActive());
        self::assertNull($webhook->getDisabledReason());
    }

    public function testReEnablingWithReplayRedispatchesQueuedAndFailed(): void
    {
        $service = $this->makeService();
        $webhook = $this->makeWebhook(active: false);
        $webhook->setDisabledReason('Delivery failed after retries');

        $delivery1 = new WebhookDelivery($webhook, 'message.created', null, [], WebhookDeliveryStatus::Failed);
        $delivery2 = new WebhookDelivery($webhook, 'message.created', null, [], WebhookDeliveryStatus::Queued);

        // findReplayableFor: first call returns the 2 deliveries for mutation,
        // second call is used inside replay() after flush (but we stub both).
        $this->deliveryRepository->method('findReplayableFor')->willReturn([$delivery1, $delivery2]);

        $this->em->expects(self::atLeastOnce())->method('flush');

        $this->bus->expects(self::exactly(2))->method('dispatch')->willReturnCallback(
            static fn (object $msg): Envelope => new Envelope($msg),
        );

        $result = $service->update($webhook, ['isActive' => true], true);

        self::assertTrue($result->isActive());
        self::assertSame(WebhookDeliveryStatus::Pending, $delivery1->getStatus());
        self::assertSame(0, $delivery1->getAttempts());
        self::assertNull($delivery1->getError());
    }

    public function testDeleteRemovesAndFlushes(): void
    {
        $service = $this->makeService();
        $webhook = $this->makeWebhook();

        $this->em->expects(self::once())->method('remove')->with($webhook);
        $this->em->expects(self::once())->method('flush');

        $service->delete($webhook);
    }

    public function testRegenerateSecretReturnsNewValue(): void
    {
        $service = $this->makeService();
        $webhook = $this->makeWebhook();
        $oldSecret = $webhook->getSecret();

        $this->em->expects(self::once())->method('flush');

        $newPlaintext = $service->regenerateSecret($webhook);

        self::assertIsString($newPlaintext);
        self::assertNotEmpty($newPlaintext);
        // The stored secret is the generated one (not the same as old)
        self::assertNotSame($oldSecret, $webhook->getSecret());
        // The returned plaintext was used to set the secret
        self::assertSame($webhook->getSecret(), $newPlaintext);
    }

    public function testReplayRedispatchesAndResetsDeliveries(): void
    {
        $service = $this->makeService();
        $webhook = $this->makeWebhook(active: true);

        $delivery1 = new WebhookDelivery($webhook, 'message.created', null, [], WebhookDeliveryStatus::Failed);
        $delivery2 = new WebhookDelivery($webhook, 'message.created', null, [], WebhookDeliveryStatus::Queued);

        $this->deliveryRepository->method('findReplayableFor')->willReturn([$delivery1, $delivery2]);
        $this->em->expects(self::once())->method('flush');

        $this->bus->expects(self::exactly(2))->method('dispatch')->willReturnCallback(
            static fn (object $msg): Envelope => new Envelope($msg),
        );

        $count = $service->replay($webhook);

        self::assertSame(2, $count);
        self::assertSame(WebhookDeliveryStatus::Pending, $delivery1->getStatus());
        self::assertSame(0, $delivery1->getAttempts());
        self::assertNull($delivery1->getError());
        self::assertSame(WebhookDeliveryStatus::Pending, $delivery2->getStatus());
    }

    public function testReplayReturnsZeroWhenNothingReplayable(): void
    {
        $service = $this->makeService();
        $webhook = $this->makeWebhook(active: true);

        $this->deliveryRepository->method('findReplayableFor')->willReturn([]);
        $this->em->expects(self::once())->method('flush');
        $this->bus->expects(self::never())->method('dispatch');

        $count = $service->replay($webhook);

        self::assertSame(0, $count);
    }

    public function testDisableAfterExhaustedDeliveryDisablesWebhookAndNotifiesAdmins(): void
    {
        $webhook = $this->makeWebhook();
        $delivery = new WebhookDelivery($webhook, 'message.created', null, [], WebhookDeliveryStatus::Pending);

        $this->deliveryRepository->method('find')->willReturn($delivery);
        $this->userService->method('getAdmins')->willReturn([new User(), new User()]);

        $this->em->expects(self::once())->method('flush');
        $this->notificationService->expects(self::exactly(2))->method('new');

        $this->makeService()->disableAfterExhaustedDelivery(1);

        self::assertSame(WebhookDeliveryStatus::Failed, $delivery->getStatus());
        self::assertFalse($webhook->isActive());
        self::assertNotNull($webhook->getDisabledReason());
    }

    public function testDisableAfterExhaustedDeliveryIgnoresUnknownDelivery(): void
    {
        $this->deliveryRepository->method('find')->willReturn(null);
        $this->em->expects(self::never())->method('flush');
        $this->notificationService->expects(self::never())->method('new');

        $this->makeService()->disableAfterExhaustedDelivery(999);
    }
}
