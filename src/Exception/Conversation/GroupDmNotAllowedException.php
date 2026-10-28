<?php

declare(strict_types=1);

namespace App\Exception\Conversation;

use App\Enum\Conversation\PolicyReason;
use App\Exception\DomainExceptionInterface;
use App\Exception\TranslationParamsInterface;

class GroupDmNotAllowedException extends \DomainException implements DomainExceptionInterface, TranslationParamsInterface
{
    /**
     * @param list<int>    $userIds
     * @param list<string> $names
     */
    public function __construct(
        public readonly PolicyReason $reason,
        public readonly array $userIds,
        private readonly array $names,
    ) {
        parent::__construct(sprintf('Conversation not allowed: %s.', $reason->value));
    }

    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.conversation.group_not_allowed.'.$this->reason->value;
    }

    #[\Override]
    public function translationParams(): array
    {
        return ['%names%' => implode(', ', $this->names)];
    }
}
