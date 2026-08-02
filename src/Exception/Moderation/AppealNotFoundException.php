<?php

declare(strict_types=1);

namespace App\Exception\Moderation;

use App\Exception\DomainExceptionInterface;

class AppealNotFoundException extends \RuntimeException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 404;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.appeal_not_found';
    }
}
