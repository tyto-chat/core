<?php

declare(strict_types=1);

namespace App\Exception\Message;

use App\Exception\DomainExceptionInterface;

class MessageNotFoundException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 404;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.message.not_found';
    }
}
