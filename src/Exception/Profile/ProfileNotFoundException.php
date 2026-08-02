<?php

declare(strict_types=1);

namespace App\Exception\Profile;

use App\Exception\DomainExceptionInterface;

class ProfileNotFoundException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 404;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.profile_not_found';
    }
}
