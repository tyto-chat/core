<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\PushSubscription;

interface WebPushSenderInterface
{
    public function isConfigured(): bool;

    /**
     * False = subscription gone (404/410), prune it; true on success or transient failure.
     *
     * @param array<string, mixed> $payload
     */
    public function send(PushSubscription $subscription, array $payload): bool;
}
