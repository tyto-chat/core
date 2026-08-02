<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhook;

use App\Async\DispatchWebhookMessage;
use App\EventListener\WebhookFailureListener;
use App\Service\Webhook\WebhookServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

#[AllowMockObjectsWithoutExpectations]
class WebhookFailureListenerTest extends TestCase
{
    private WebhookServiceInterface&MockObject $webhookService;

    #[\Override]
    protected function setUp(): void
    {
        $this->webhookService = $this->createMock(WebhookServiceInterface::class);
    }

    private function makeEvent(object $message, bool $willRetry): WorkerMessageFailedEvent
    {
        $event = new WorkerMessageFailedEvent(new Envelope($message), 'webhook', new \RuntimeException('Failed'));
        if ($willRetry) {
            $event->setForRetry();
        }

        return $event;
    }

    public function testExhaustedDeliveryDelegatesToService(): void
    {
        $this->webhookService->expects(self::once())
            ->method('disableAfterExhaustedDelivery')
            ->with(42);

        $listener = new WebhookFailureListener($this->webhookService);
        $listener($this->makeEvent(new DispatchWebhookMessage(42), willRetry: false));
    }

    public function testWillRetryTrueDoesNothing(): void
    {
        $this->webhookService->expects(self::never())->method('disableAfterExhaustedDelivery');

        $listener = new WebhookFailureListener($this->webhookService);
        $listener($this->makeEvent(new DispatchWebhookMessage(1), willRetry: true));
    }

    public function testNonWebhookMessageIgnored(): void
    {
        $this->webhookService->expects(self::never())->method('disableAfterExhaustedDelivery');

        $listener = new WebhookFailureListener($this->webhookService);
        $listener($this->makeEvent(new \stdClass(), willRetry: false));
    }
}
