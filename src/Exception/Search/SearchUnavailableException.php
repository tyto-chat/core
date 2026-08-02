<?php

declare(strict_types=1);

namespace App\Exception\Search;

use App\Exception\DomainExceptionInterface;

class SearchUnavailableException extends \RuntimeException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 503;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.search.unavailable';
    }
}
