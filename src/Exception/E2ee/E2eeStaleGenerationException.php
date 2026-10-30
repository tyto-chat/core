<?php

declare(strict_types=1);

namespace App\Exception\E2ee;

use App\Exception\DomainExceptionInterface;
use App\Exception\ErrorDetailsInterface;

class E2eeStaleGenerationException extends \DomainException implements DomainExceptionInterface, ErrorDetailsInterface
{
    public function __construct(private readonly int $currentGeneration)
    {
        parent::__construct('The encryption identity changed in the meantime.');
    }

    #[\Override]
    public function statusCode(): int
    {
        return 409;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.e2ee.stale_generation';
    }

    #[\Override]
    public function errorDetails(): array
    {
        return ['code' => 'stale_generation', 'currentGeneration' => $this->currentGeneration];
    }
}
