<?php

declare(strict_types=1);

namespace App\Exception\User;

use App\Exception\DomainExceptionInterface;

class AccountPendingDeletionException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 423;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.account_pending_deletion';
    }
}
