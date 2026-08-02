<?php

declare(strict_types=1);

namespace App\Exception\UserGroup;

use App\Exception\DomainExceptionInterface;

class CannotLeaveOwnedGroupException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.user_group.cannot_leave_owned';
    }
}
