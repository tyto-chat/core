<?php

declare(strict_types=1);

namespace App\Exception\User;

use App\Exception\DomainExceptionInterface;

class EmailAlreadyTakenException extends \DomainException implements DomainExceptionInterface
{
    public function __construct(public string $email)
    {
        parent::__construct(sprintf('CreateUserDto with email "%s" is already taken.', $email));
    }

    #[\Override]
    public function statusCode(): int
    {
        return 409;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.email_already_taken';
    }
}
