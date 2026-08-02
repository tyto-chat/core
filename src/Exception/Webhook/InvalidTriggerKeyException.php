<?php

declare(strict_types=1);

namespace App\Exception\Webhook;

use App\Exception\DomainExceptionInterface;

class InvalidTriggerKeyException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.webhook.invalid_trigger_key';
    }
}
