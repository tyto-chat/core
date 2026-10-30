<?php

declare(strict_types=1);

namespace App\Exception\E2ee;

use App\Exception\DomainExceptionInterface;
use App\Exception\ErrorDetailsInterface;

class E2eeHistoryHeadMismatchException extends \DomainException implements DomainExceptionInterface, ErrorDetailsInterface
{
    public function __construct(private readonly int $head)
    {
        parent::__construct('The history record does not follow the device\'s last record.');
    }

    #[\Override]
    public function statusCode(): int
    {
        return 409;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.e2ee.history_head';
    }

    #[\Override]
    public function errorDetails(): array
    {
        return ['code' => 'history_head', 'head' => $this->head];
    }
}
