<?php

declare(strict_types=1);

namespace App\Service\Webhook;

use App\Dto\Webhook\WebhookEventContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.webhook_trigger')]
interface WebhookTriggerInterface
{
    public function getKey(): string;

    public function getLabel(): string;

    public function getDescription(): string;

    /**
     * @return string[]
     */
    public function getFilterFields(): array;

    /**
     * @return string[]
     */
    public function getRequiredFilterFields(): array;

    /**
     * @param array<string,mixed>|null $filters
     */
    public function matches(?array $filters, WebhookEventContext $ctx): bool;

    /**
     * @return array<string,mixed>
     */
    public function buildPayload(WebhookEventContext $ctx): array;
}
