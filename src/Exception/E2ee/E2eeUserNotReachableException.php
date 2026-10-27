<?php

declare(strict_types=1);

namespace App\Exception\E2ee;

use App\Exception\DomainExceptionInterface;
use App\Exception\TranslationParamsInterface;

class E2eeUserNotReachableException extends \DomainException implements DomainExceptionInterface, TranslationParamsInterface
{
    public function __construct(private readonly string $userName)
    {
        parent::__construct('User has no device with a usable key package.');
    }

    #[\Override]
    public function statusCode(): int
    {
        return 409;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.e2ee.not_reachable';
    }

    #[\Override]
    public function translationParams(): array
    {
        return ['%name%' => $this->userName];
    }
}
