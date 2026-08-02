<?php

declare(strict_types=1);

namespace App\Exception\Moderation;

use App\Exception\DomainExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class ImmuneTargetException extends AccessDeniedException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 403;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.immune_target';
    }
}
