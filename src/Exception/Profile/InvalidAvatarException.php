<?php

declare(strict_types=1);

namespace App\Exception\Profile;

use App\Exception\DomainExceptionInterface;

class InvalidAvatarException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.profile.invalid_avatar';
    }
}
