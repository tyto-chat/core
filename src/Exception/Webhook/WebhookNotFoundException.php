<?php

declare(strict_types=1);

namespace App\Exception\Webhook;

use App\Exception\DomainExceptionInterface;

class WebhookNotFoundException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 404;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.webhook.not_found';
    }
}
