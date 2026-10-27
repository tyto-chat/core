<?php

declare(strict_types=1);

namespace App\Exception\E2ee;

use App\Exception\DomainExceptionInterface;

class InvalidE2eeCredentialException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.e2ee.invalid_credential';
    }
}
