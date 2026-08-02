<?php

declare(strict_types=1);

namespace App\Exception\Community;

use App\Exception\DomainExceptionInterface;

class AlreadyMemberException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 409;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.already_member';
    }
}
