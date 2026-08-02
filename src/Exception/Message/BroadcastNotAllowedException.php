<?php

declare(strict_types=1);

namespace App\Exception\Message;

use App\Exception\DomainExceptionInterface;

class BroadcastNotAllowedException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 403;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.message.broadcast_not_allowed';
    }
}
