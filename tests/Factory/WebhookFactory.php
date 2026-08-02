<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Webhook;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Webhook>
 */
final class WebhookFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return Webhook::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'name' => implode(' ', (array) self::faker()->words(3)).' Hook',
            'url' => 'https://example.test/hook',
            'triggerKey' => 'message.created',
            'secret' => bin2hex(random_bytes(32)),
            'isActive' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->with([
            'isActive' => false,
            'disabledReason' => 'Delivery failed after retries',
        ]);
    }

    public function withTrigger(string $triggerKey): static
    {
        return $this->with(['triggerKey' => $triggerKey]);
    }
}
