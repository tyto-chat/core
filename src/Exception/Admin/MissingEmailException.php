<?php

declare(strict_types=1);

namespace App\Exception\Admin;

use App\Exception\DomainExceptionInterface;

class MissingEmailException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.admin.email_required';
    }
}
