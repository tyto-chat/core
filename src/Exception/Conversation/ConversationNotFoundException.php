<?php

declare(strict_types=1);

namespace App\Exception\Conversation;

use App\Exception\DomainExceptionInterface;

class ConversationNotFoundException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 404;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.conversation.not_found';
    }
}
