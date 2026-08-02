<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\Security\Core\Exception\AccessDeniedException as SymfonyAccessDeniedException;

// Extends the Symfony class so anonymous denials still get the firewall's 401 challenge.
class AccessDeniedException extends SymfonyAccessDeniedException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 403;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.access_denied';
    }
}
