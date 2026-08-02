<?php

declare(strict_types=1);

namespace App\Service\Notification;

interface EmailDigestServiceInterface
{
    public function dispatchDue(): int;
}
