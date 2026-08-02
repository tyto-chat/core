<?php

declare(strict_types=1);

namespace App\Exception\Community;

use App\Exception\DomainExceptionInterface;

class InvalidWelcomeChannelException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.invalid_welcome_channel';
    }
}
