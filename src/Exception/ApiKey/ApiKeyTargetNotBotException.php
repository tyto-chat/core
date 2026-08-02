<?php

declare(strict_types=1);

namespace App\Exception\ApiKey;

use App\Exception\DomainExceptionInterface;

class ApiKeyTargetNotBotException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.api_key_target_not_bot';
    }
}
