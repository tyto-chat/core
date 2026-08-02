<?php

declare(strict_types=1);

namespace App\Exception\Notification;

use App\Exception\DomainExceptionInterface;

class NotificationNotFoundException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 404;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.notification_not_found';
    }
}
