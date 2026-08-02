<?php

declare(strict_types=1);

namespace App\Exception\User;

use App\Exception\DomainExceptionInterface;

class DataExportNotReadyException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 404;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.data_export_not_ready';
    }
}
