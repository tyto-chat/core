<?php

declare(strict_types=1);

namespace App\Exception\Moderation;

use App\Exception\AccessDeniedException;

class TimedOutException extends AccessDeniedException
{
    #[\Override]
    public function translationKey(): string
    {
        return 'exception.moderation.timed_out';
    }
}
