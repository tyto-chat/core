<?php

declare(strict_types=1);

namespace App\Exception\Message;

use App\Exception\DomainExceptionInterface;
use App\Exception\TranslationParamsInterface;

class TooManyPinnedMessagesException extends \DomainException implements DomainExceptionInterface, TranslationParamsInterface
{
    public function __construct(private readonly int $limit)
    {
        parent::__construct(sprintf('A channel can have at most %d pinned messages.', $limit));
    }

    #[\Override]
    public function translationParams(): array
    {
        return ['%limit%' => (string) $this->limit];
    }

    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.message.too_many_pinned';
    }
}
