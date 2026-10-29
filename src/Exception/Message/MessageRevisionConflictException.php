<?php

declare(strict_types=1);

namespace App\Exception\Message;

use App\Exception\DomainExceptionInterface;
use App\Exception\ErrorDetailsInterface;

class MessageRevisionConflictException extends \DomainException implements DomainExceptionInterface, ErrorDetailsInterface
{
    public function __construct(private readonly int $currentRevisionCount)
    {
        parent::__construct('The message was edited in the meantime.');
    }

    #[\Override]
    public function statusCode(): int
    {
        return 409;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.message.revision_conflict';
    }

    #[\Override]
    public function errorDetails(): array
    {
        return ['code' => 'revision_conflict', 'currentRevisionCount' => $this->currentRevisionCount];
    }
}
