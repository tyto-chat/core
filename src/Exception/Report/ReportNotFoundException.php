<?php

declare(strict_types=1);

namespace App\Exception\Report;

use App\Exception\DomainExceptionInterface;

class ReportNotFoundException extends \RuntimeException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 404;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.report_not_found';
    }
}
