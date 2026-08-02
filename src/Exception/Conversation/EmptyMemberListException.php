<?php

declare(strict_types=1);

namespace App\Exception\Conversation;

use App\Exception\DomainExceptionInterface;

class EmptyMemberListException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.conversation.empty_member_list';
    }
}
