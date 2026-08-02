<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhook;

use App\Async\DispatchWebhookMessage;
use App\Dto\Webhook\WebhookEventContext;
use App\Entity\Webhook;
use App\Entity\WebhookDelivery;
use App\Enum\Webhook\WebhookDeliveryStatus;
use App\Repository\WebhookDeliveryRepository;
use App\Repository\WebhookRepository;
use App\Service\Settings\SettingsServiceInterface;
use App\Service\Webhook\WebhookEmitter;
use App\Service\Webhook\WebhookTriggerInterface;
use App\Service\Webhook\WebhookTriggerRegistry;
use App\Settings\SettingDef;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
class WebhookEmitterTest extends TestCase
{
    private WebhookRepository&MockObject $webhookRepository;
    private WebhookDeliveryRepository&MockObject $deliveryRepository;
    private EntityManagerInterface&MockObject $em;
    private MessageBusInterface&MockObject $bus;
    private SettingsServiceInterface&MockObject $settings;
    private int $webhookMaxQueued = 1000;

    #[\Override]
    protected function setUp(): void
    {
        $this->webhookRepository = $this->createMock(WebhookRepository::class);
        $this->deliveryRepository = $this->createMock(WebhookDeliveryRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->settings = $this->createMock(SettingsServiceInterface::class);
        $this->settings->method('get')->willReturnCallback(function (SettingDef $def): mixed {
            return match ($def->key) {
                'webhookMaxQueued' => $this->webhookMaxQueued,
                default => null,
            };
        });
    }

    private function makeEmitter(WebhookTriggerRegistry $registry): WebhookEmitter
    {
        $emitter = new WebhookEmitter(
            $this->webhookRepository,
            $this->deliveryRepository,
            $registry,
            $this->em,
            $this->bus,
            $this->settings,
        );
        $emitter->setLogger(new NullLogger());

        return $emitter;
    }

    private function makeWebhook(bool $active = true): Webhook
    {
        $webhook = new Webhook();
        $webhook->setName('Test');
        $webhook->setUrl('https://example.com/hook');
        $webhook->setTriggerKey('message.created');
        $webhook->setSecret('secret');
        $webhook->setIsActive($active);

        return $webhook;
    }

    private function makeTrigger(): WebhookTriggerInterface
    {
        return new class implements WebhookTriggerInterface {
            public function getKey(): string
            {
                return 'message.created';
            }

            public function getLabel(): string
            {
                return 'Message created';
            }

            public function getDescription(): string
            {
                return 'Fired when a message is created';
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
                return ['event' => 'message.created'];
            }
        };
    }

    public function testActiveWebhookCreatesPendingDeliveryAndDispatches(): void
    {
        $webhook = $this->makeWebhook(active: true);
        $trigger = $this->makeTrigger();
        $emitter = $this->makeEmitter(new WebhookTriggerRegistry([$trigger]));

        $this->webhookRepository->method('findByTriggerKey')->willReturn([$webhook]);

        $persisted = null;
        $this->em->expects(self::once())->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted = $entity;
            },
        );
        $this->em->expects(self::once())->method('flush');

        $dispatched = null;
        $this->bus->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (object $msg) use (&$dispatched): Envelope {
                $dispatched = $msg;

                return new Envelope($msg);
            },
        );

        $emitter->emit('message.created', new WebhookEventContext());

        self::assertInstanceOf(WebhookDelivery::class, $persisted);
        self::assertSame(WebhookDeliveryStatus::Pending, $persisted->getStatus());
        self::assertInstanceOf(DispatchWebhookMessage::class, $dispatched);
    }

    public function testInactiveWebhookQueuesWithoutDispatch(): void
    {
        $webhook = $this->makeWebhook(active: false);
        $trigger = $this->makeTrigger();
        $emitter = $this->makeEmitter(new WebhookTriggerRegistry([$trigger]));

        $this->webhookRepository->method('findByTriggerKey')->willReturn([$webhook]);
        $this->deliveryRepository->method('countQueuedFor')->willReturn(0); // below cap

        $persisted = null;
        $this->em->expects(self::once())->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted = $entity;
            },
        );
        $this->em->expects(self::once())->method('flush');
        $this->bus->expects(self::never())->method('dispatch');

        $emitter->emit('message.created', new WebhookEventContext());

        self::assertInstanceOf(WebhookDelivery::class, $persisted);
        self::assertSame(WebhookDeliveryStatus::Queued, $persisted->getStatus());
    }

    public function testQueueCapSkipsRowCreation(): void
    {
        $webhook = $this->makeWebhook(active: false);
        $trigger = $this->makeTrigger();
        $emitter = $this->makeEmitter(new WebhookTriggerRegistry([$trigger]));

        // Set cap to 1000 (default) and return 1000 queued (at/above cap)
        $this->webhookMaxQueued = 1000;

        $this->webhookRepository->method('findByTriggerKey')->willReturn([$webhook]);
        $this->deliveryRepository->method('countQueuedFor')->willReturn(1000); // at cap

        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('flush');
        $this->bus->expects(self::never())->method('dispatch');

        $emitter->emit('message.created', new WebhookEventContext());
    }

    public function testUnknownTriggerKeyNoOp(): void
    {
        // Empty registry — 'no.such.trigger' won't be found
        $emitter = $this->makeEmitter(new WebhookTriggerRegistry([]));

        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('flush');
        $this->bus->expects(self::never())->method('dispatch');

        $emitter->emit('no.such.trigger', new WebhookEventContext());
    }
}
