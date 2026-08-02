<?php

declare(strict_types=1);

namespace App\Async;

final class DispatchWebhookMessage
{
    public function __construct(public readonly int $deliveryId)
    {
    }
}
