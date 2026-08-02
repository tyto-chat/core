<?php

declare(strict_types=1);

namespace App\Exception\Moderation;

use App\Exception\DomainExceptionInterface;

class AppealNotAllowedException extends \RuntimeException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.appeal_not_allowed';
    }
}
