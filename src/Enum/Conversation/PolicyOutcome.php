<?php

declare(strict_types=1);

namespace App\Enum\Conversation;

enum PolicyOutcome: string
{
    case Encrypted = 'encrypted';
    case Plain = 'plain';
    case PlainFallback = 'plain_fallback';
    case Blocked = 'blocked';
}
