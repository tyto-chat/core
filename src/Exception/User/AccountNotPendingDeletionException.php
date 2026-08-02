<?php

declare(strict_types=1);

namespace App\Exception\User;

use App\Exception\DomainExceptionInterface;

class AccountNotPendingDeletionException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 409;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.account_not_pending_deletion';
    }
}
