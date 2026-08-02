<?php

declare(strict_types=1);

namespace App\Enum\Message;

use App\Enum\BackedEnumValuesTrait;

/** System = bot-authored event posts: author hidden, notifications suppressed, never indexed. */
enum MessageKind: string
{
    use BackedEnumValuesTrait;

    case Standard = 'standard';
    case System = 'system';
}
