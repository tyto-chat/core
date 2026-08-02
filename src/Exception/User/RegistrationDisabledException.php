<?php

declare(strict_types=1);

namespace App\Exception\User;

use App\Exception\DomainExceptionInterface;

class RegistrationDisabledException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 403;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.registration_disabled';
    }
}
