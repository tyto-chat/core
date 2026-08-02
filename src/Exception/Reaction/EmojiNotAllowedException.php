<?php

declare(strict_types=1);

namespace App\Exception\Reaction;

use App\Exception\DomainExceptionInterface;

class EmojiNotAllowedException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.reaction.emoji_not_allowed';
    }
}
