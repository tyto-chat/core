<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhook;

use App\Async\DispatchWebhookMessage;
use App\Async\Handler\DispatchWebhookHandler;
use App\Entity\Webhook;
use App\Entity\WebhookDelivery;
use App\Enum\Webhook\WebhookDeliveryStatus;
use App\Repository\WebhookDeliveryRepository;
use App\Service\Settings\SettingsServiceInterface;
use App\Service\Webhook\WebhookSigner;
use App\Service\Webhook\WebhookUrlGuard;
use App\Settings\SettingDef;
use App\Settings\Settings;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[AllowMockObjectsWithoutExpectations]
class DispatchWebhookHandlerTest extends TestCase
{
    private WebhookDeliveryRepository&MockObject $deliveryRepository;
    private EntityManagerInterface&MockObject $em;
    private SettingsServiceInterface&MockObject $settings;
    private WebhookSigner $signer;
    private WebhookUrlGuard $urlGuard;

    #[\Override]
    protected function setUp(): void
    {
        $this->deliveryRepository = $this->createMock(WebhookDeliveryRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->settings = $this->createMock(SettingsServiceInterface::class);
        // Default: internal URLs not allowed (matches Settings::webhookAllowInternalUrls default = false)
        $this->settings->method('get')->willReturnCallback(static function (SettingDef $def): mixed {
            return match ($def->key) {
                'webhookAllowInternalUrls' => false,
                default => null,
            };
        });
        $this->signer = new WebhookSigner();
        $this->urlGuard = new WebhookUrlGuard();
    }

    private function makeHandler(MockHttpClient $httpClient): DispatchWebhookHandler
    {
        return new DispatchWebhookHandler(
            $this->deliveryRepository,
            $this->em,
            $httpClient,
            $this->signer,
            $this->urlGuard,
            $this->settings,
        );
    }

    private function makeWebhook(string $url = 'https://example.com/hook'): Webhook
    {
        $webhook = new Webhook();
        $webhook->setName('Test Webhook');
        $webhook->setUrl($url);
        $webhook->setTriggerKey('message.created');
        $webhook->setSecret('test-secret');
        $webhook->setIsActive(true);

        return $webhook;
    }

    private function makeDelivery(Webhook $webhook): WebhookDelivery
    {
        return new WebhookDelivery(
            $webhook,
            'message.created',
            null,
            ['event' => 'message.created'],
            WebhookDeliveryStatus::Pending,
        );
    }

    public function testSuccessUpdatesDeliveryToSuccess(): void
    {
        $webhook = $this->makeWebhook();
        $delivery = $this->makeDelivery($webhook);

        $this->deliveryRepository->method('find')->willReturn($delivery);

        $httpClient = new MockHttpClient(new MockResponse('OK', ['http_code' => 200]));
        $handler = $this->makeHandler($httpClient);

        $this->em->expects(self::once())->method('flush');

        $handler(new DispatchWebhookMessage(1));

        self::assertSame(WebhookDeliveryStatus::Success, $delivery->getStatus());
        self::assertSame(200, $delivery->getHttpCode());
        self::assertSame(1, $delivery->getAttempts());
    }

    public function testNon2xxThrowsForRetry(): void
    {
        $webhook = $this->makeWebhook();
        $delivery = $this->makeDelivery($webhook);

        $this->deliveryRepository->method('find')->willReturn($delivery);

        $httpClient = new MockHttpClient(new MockResponse('Internal Server Error', ['http_code' => 500]));
        $handler = $this->makeHandler($httpClient);

        $this->em->expects(self::once())->method('flush');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 500/');

        $handler(new DispatchWebhookMessage(1));

        // delivery should NOT be marked Failed (exhaustion is handled by listener)
        self::assertNotSame(WebhookDeliveryStatus::Failed, $delivery->getStatus());
        self::assertSame(1, $delivery->getAttempts());
    }

    public function testSsrfBlockedUrlMarksFailedAndDoesNotThrow(): void
    {
        $webhook = $this->makeWebhook('http://127.0.0.1/hook');
        $delivery = $this->makeDelivery($webhook);

        // allowInternal = false (default on ServerConfig)
        $this->deliveryRepository->method('find')->willReturn($delivery);

        $httpClient = new MockHttpClient(static function () {
            throw new \RuntimeException('Should not be called');
        });
        $handler = $this->makeHandler($httpClient);

        $this->em->expects(self::once())->method('flush');

        // No throw — SSRF is terminal (no retry)
        $handler(new DispatchWebhookMessage(1));

        self::assertSame(WebhookDeliveryStatus::Failed, $delivery->getStatus());
        self::assertStringContainsString('SSRF', (string) $delivery->getError());
    }

    public function testMissingDeliverySkips(): void
    {
        $this->deliveryRepository->method('find')->willReturn(null);

        $httpClient = new MockHttpClient();
        $handler = $this->makeHandler($httpClient);

        $this->em->expects(self::never())->method('flush');

        // No exception, no-op
        $handler(new DispatchWebhookMessage(999));
    }
}
