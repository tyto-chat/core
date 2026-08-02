<?php

declare(strict_types=1);

namespace App\Dto\Webhook;

use App\Entity\User;

final class WebhookEventContext
{
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(
        public readonly ?User $actor = null,
        public readonly array $data = [],
    ) {
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }
}
