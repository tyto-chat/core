<?php

declare(strict_types=1);

namespace App\Exception\E2ee;

use App\Exception\DomainExceptionInterface;
use App\Exception\ErrorDetailsInterface;

class E2eeStaleEpochException extends \DomainException implements DomainExceptionInterface, ErrorDetailsInterface
{
    public function __construct(private readonly int $currentEpoch)
    {
        parent::__construct('The group moved on; fetch the missing commits and retry.');
    }

    #[\Override]
    public function statusCode(): int
    {
        return 409;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.e2ee.stale_epoch';
    }

    #[\Override]
    public function errorDetails(): array
    {
        return ['code' => 'stale_epoch', 'currentEpoch' => $this->currentEpoch];
    }
}
