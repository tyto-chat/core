<?php

declare(strict_types=1);

namespace App\Service\Webhook;

use App\Entity\Webhook;

interface WebhookServiceInterface
{
    /**
     * @throws \App\Exception\Webhook\WebhookNotFoundException (404)
     */
    public function getById(int $id): Webhook;

    /**
     * @param array<string,mixed>|null $filters
     *
     * @return array{webhook: Webhook, secret: string}
     *
     * @throws \App\Exception\Webhook\InvalidTriggerKeyException when the triggerKey is not registered (422)
     */
    public function create(string $name, string $url, string $triggerKey, ?array $filters): array;

    /**
     * @param array<string,mixed> $changes Accepted keys: name, url, filters, isActive
     */
    public function update(Webhook $webhook, array $changes, bool $replayPending): Webhook;

    public function delete(Webhook $webhook): void;

    public function regenerateSecret(Webhook $webhook): string;

    /**
     * @return int count of deliveries re-queued
     */
    public function replay(Webhook $webhook): int;

    /**
     * @return array{deliveryId: int, status: string}
     *
     * @throws \App\Exception\Webhook\InvalidTriggerKeyException if the trigger is no longer registered
     */
    public function sendTest(Webhook $webhook): array;

    /** Deliberately not admin-gated — the messenger worker runs token-less. */
    public function disableAfterExhaustedDelivery(int $deliveryId): void;
}
