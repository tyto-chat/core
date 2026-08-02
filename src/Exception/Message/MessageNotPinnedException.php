<?php

declare(strict_types=1);

namespace App\Exception\Message;

use App\Exception\DomainExceptionInterface;

class MessageNotPinnedException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.message.not_pinned';
    }
}
