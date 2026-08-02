<?php

declare(strict_types=1);

namespace App\Exception\Admin;

use App\Exception\DomainExceptionInterface;

class DeleteConfirmationMismatchException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 400;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.admin.delete_confirmation_mismatch';
    }
}
