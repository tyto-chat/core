<?php

declare(strict_types=1);

namespace App\Exception\User;

use App\Exception\DomainExceptionInterface;

class InvalidCurrentPasswordException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 400;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.user.invalid_current_password';
    }
}
