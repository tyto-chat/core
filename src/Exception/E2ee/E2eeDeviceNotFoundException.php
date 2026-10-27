<?php

declare(strict_types=1);

namespace App\Exception\E2ee;

use App\Exception\DomainExceptionInterface;

class E2eeDeviceNotFoundException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 404;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.e2ee.device_not_found';
    }
}
