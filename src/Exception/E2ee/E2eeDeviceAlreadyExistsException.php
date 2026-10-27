<?php

declare(strict_types=1);

namespace App\Exception\E2ee;

use App\Exception\DomainExceptionInterface;

class E2eeDeviceAlreadyExistsException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 409;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.e2ee.device_exists';
    }
}
