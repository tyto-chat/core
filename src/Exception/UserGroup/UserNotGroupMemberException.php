<?php

declare(strict_types=1);

namespace App\Exception\UserGroup;

use App\Exception\DomainExceptionInterface;

class UserNotGroupMemberException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 404;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.user_not_group_member';
    }
}
