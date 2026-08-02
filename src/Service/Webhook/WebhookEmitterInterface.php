<?php

declare(strict_types=1);

namespace App\Service\Webhook;

use App\Dto\Webhook\WebhookEventContext;

interface WebhookEmitterInterface
{
    public function emit(string $triggerKey, WebhookEventContext $ctx): void;
}
