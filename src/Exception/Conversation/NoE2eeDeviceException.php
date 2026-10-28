<?php

declare(strict_types=1);

namespace App\Exception\Conversation;

use App\Exception\DomainExceptionInterface;
use App\Exception\TranslationParamsInterface;

class NoE2eeDeviceException extends \DomainException implements DomainExceptionInterface, TranslationParamsInterface
{
    /**
     * @param list<int>    $userIds
     * @param list<string> $names
     */
    public function __construct(
        public readonly array $userIds,
        private readonly array $names,
        private readonly bool $includesCaller,
    ) {
        parent::__construct('A participant cannot take part in an encrypted conversation.');
    }

    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return $this->includesCaller ? 'exception.conversation.no_e2ee_device_self' : 'exception.conversation.no_e2ee_device';
    }

    #[\Override]
    public function translationParams(): array
    {
        return ['%names%' => implode(', ', $this->names)];
    }
}
