<?php

declare(strict_types=1);

namespace App\Exception\User;

use App\Exception\DomainExceptionInterface;

class AccountAlreadyPendingDeletionException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 409;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.account_already_pending_deletion';
    }
}
