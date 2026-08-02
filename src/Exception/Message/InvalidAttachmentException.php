<?php

declare(strict_types=1);

namespace App\Exception\Message;

use App\Exception\DomainExceptionInterface;

class InvalidAttachmentException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.message.invalid_attachment';
    }
}
