<?php

declare(strict_types=1);

namespace App\Exception\Community;

use App\Exception\DomainExceptionInterface;

class InviteNoLongerValidException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 410;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.invite_no_longer_valid';
    }
}
