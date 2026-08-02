<?php

declare(strict_types=1);

namespace App\Exception\User;

use App\Exception\DomainExceptionInterface;

class InvalidTwoFactorCodeException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 401;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.user.invalid_two_factor_code';
    }
}
