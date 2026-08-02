<?php

declare(strict_types=1);

namespace App\Exception\Reaction;

use App\Exception\DomainExceptionInterface;

class ReactionNotFoundException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 404;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.reaction.not_found';
    }
}
