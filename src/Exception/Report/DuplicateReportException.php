<?php

declare(strict_types=1);

namespace App\Exception\Report;

use App\Exception\DomainExceptionInterface;

class DuplicateReportException extends \RuntimeException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.duplicate_report';
    }
}
