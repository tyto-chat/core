<?php

declare(strict_types=1);

namespace App\Exception\MediaObject;

use App\Exception\DomainExceptionInterface;

class NotAnAttachmentException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.media.not_an_attachment';
    }
}
