<?php

declare(strict_types=1);

namespace App\Exception\Community;

use App\Exception\DomainExceptionInterface;

class CannotLeaveLastAdminException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.cannot_leave_last_admin';
    }
}
