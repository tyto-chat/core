<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhook;

use App\Dto\Webhook\WebhookEventContext;
use App\Service\Webhook\WebhookTriggerInterface;
use App\Service\Webhook\WebhookTriggerRegistry;
use PHPUnit\Framework\TestCase;

class WebhookTriggerRegistryTest extends TestCase
{
    public function testIndexesTriggersByKeyAndLooksThemUp(): void
    {
        $t = $this->makeTrigger('message.created');
        $registry = new WebhookTriggerRegistry([$t]);

        self::assertTrue($registry->has('message.created'));
        self::assertFalse($registry->has('nope'));
        self::assertSame($t, $registry->get('message.created'));
        self::assertNull($registry->get('nope'));
        self::assertCount(1, $registry->all());
    }

    public function testMultipleTriggersAllIndexed(): void
    {
        $t1 = $this->makeTrigger('message.created');
        $t2 = $this->makeTrigger('reaction.added');
        $registry = new WebhookTriggerRegistry([$t1, $t2]);

        self::assertCount(2, $registry->all());
        self::assertSame($t1, $registry->get('message.created'));
        self::assertSame($t2, $registry->get('reaction.added'));
    }

    public function testEmptyRegistryReturnsDefaults(): void
    {
        $registry = new WebhookTriggerRegistry([]);

        self::assertFalse($registry->has('anything'));
        self::assertNull($registry->get('anything'));
        self::assertCount(0, $registry->all());
    }

    private function makeTrigger(string $key): WebhookTriggerInterface
    {
        return new class($key) implements WebhookTriggerInterface {
            public function __construct(private string $key)
            {
            }

            public function getKey(): string
            {
                return $this->key;
            }

            public function getLabel(): string
            {
                return 'L';
            }

            public function getDescription(): string
            {
                return 'D';
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
}
