<?php

declare(strict_types=1);

namespace App\Exception\User;

use App\Exception\DomainExceptionInterface;

class DataExportAlreadyPendingException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 409;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.data_export_already_pending';
    }
}
