<?php

declare(strict_types=1);

namespace App\Exception\ApiKey;

use App\Exception\DomainExceptionInterface;

class EmptyScopesException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.empty_api_key_scopes';
    }
}
