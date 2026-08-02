<?php

declare(strict_types=1);

namespace App\Service\Webhook;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class WebhookTriggerRegistry
{
    /** @var array<string,WebhookTriggerInterface> */
    private array $byKey = [];

    /**
     * @param iterable<WebhookTriggerInterface> $triggers
     */
    public function __construct(
        #[AutowireIterator('app.webhook_trigger')]
        iterable $triggers,
    ) {
        foreach ($triggers as $t) {
            $this->byKey[$t->getKey()] = $t;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->byKey[$key]);
    }

    public function get(string $key): ?WebhookTriggerInterface
    {
        return $this->byKey[$key] ?? null;
    }

    /**
     * @return WebhookTriggerInterface[]
     */
    public function all(): array
    {
        return array_values($this->byKey);
    }
}
