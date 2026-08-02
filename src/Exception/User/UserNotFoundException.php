<?php

declare(strict_types=1);

namespace App\Exception\User;

use App\Exception\DomainExceptionInterface;

class UserNotFoundException extends \DomainException implements DomainExceptionInterface
{
    public function __construct(string $message = '')
    {
        parent::__construct($message);
    }

    #[\Override]
    public function statusCode(): int
    {
        return 404;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.user_not_found';
    }
}
