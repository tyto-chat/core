<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhook;

use App\Async\Handler\PruneWebhookDeliveriesHandler;
use App\Async\PruneWebhookDeliveriesMessage;
use App\Repository\WebhookDeliveryRepository;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\SettingDef;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class PruneWebhookDeliveriesHandlerTest extends TestCase
{
    private WebhookDeliveryRepository&MockObject $deliveryRepository;
    private SettingsServiceInterface&MockObject $settings;

    #[\Override]
    protected function setUp(): void
    {
        $this->deliveryRepository = $this->createMock(WebhookDeliveryRepository::class);
        $this->settings = $this->createMock(SettingsServiceInterface::class);
    }

    public function testDeletesDeliveriesOlderThanRetention(): void
    {
        $this->settings->method('get')->willReturnCallback(static function (SettingDef $def): mixed {
            return match ($def->key) {
                'webhookLogRetentionDays' => 30,
                default => null,
            };
        });

        $capturedCutoff = null;
        $this->deliveryRepository
            ->expects(self::once())
            ->method('deleteOlderThan')
            ->willReturnCallback(static function (\DateTimeImmutable $cutoff) use (&$capturedCutoff): int {
                $capturedCutoff = $cutoff;

                return 5;
            });

        $handler = new PruneWebhookDeliveriesHandler(
            $this->deliveryRepository,
            $this->settings,
        );
        $handler(new PruneWebhookDeliveriesMessage());

        self::assertInstanceOf(\DateTimeImmutable::class, $capturedCutoff);

        $expectedCutoff = new \DateTimeImmutable('-30 days');
        $diffSeconds = abs($capturedCutoff->getTimestamp() - $expectedCutoff->getTimestamp());
        self::assertLessThan(5, $diffSeconds, 'Cutoff should be within 5 seconds of now - 30 days');
    }
}
