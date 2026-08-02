<?php

declare(strict_types=1);

namespace App\Exception\Moderation;

use App\Exception\DomainExceptionInterface;

class ModeratorNoteNotFoundException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 404;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.moderation.note_not_found';
    }
}
