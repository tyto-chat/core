<?php

declare(strict_types=1);

namespace App\Exception\Community;

use App\Exception\DomainExceptionInterface;

class InviteNotFoundException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 404;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.invite_not_found';
    }
}
