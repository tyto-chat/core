<?php

declare(strict_types=1);

namespace App\Exception\Challenge;

use App\Exception\DomainExceptionInterface;

class InvalidChallengeException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.invalid_challenge';
    }
}
