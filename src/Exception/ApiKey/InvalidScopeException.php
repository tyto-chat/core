<?php

declare(strict_types=1);

namespace App\Exception\ApiKey;

use App\Exception\DomainExceptionInterface;

class InvalidScopeException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.invalid_api_key_scope';
    }
}
