<?php

declare(strict_types=1);

namespace App\Enum\Moderation;

enum ReportStatus: string
{
    case Open = 'open';
    case Escalated = 'escalated';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function isFinal(): bool
    {
        return self::Resolved === $this || self::Dismissed === $this;
    }
}
