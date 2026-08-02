<?php

declare(strict_types=1);

namespace App\Exception\User;

use App\Exception\DomainExceptionInterface;
use App\Exception\HttpHeadersAwareExceptionInterface;

class DataExportCooldownException extends \DomainException implements DomainExceptionInterface, HttpHeadersAwareExceptionInterface
{
    public function __construct(string $message, private readonly int $retryAfterSeconds = 0)
    {
        parent::__construct($message);
    }

    #[\Override]
    public function statusCode(): int
    {
        return 429;
    }

    #[\Override]
    public function httpHeaders(): array
    {
        return $this->retryAfterSeconds > 0 ? ['Retry-After' => (string) $this->retryAfterSeconds] : [];
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.data_export_cooldown';
    }
}
