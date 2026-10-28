<?php

declare(strict_types=1);

namespace App\Exception\Conversation;

use App\Exception\DomainExceptionInterface;

class EncryptedGroupNotAvailableException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.conversation.encrypted_group_not_available';
    }
}
