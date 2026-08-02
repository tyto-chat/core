<?php

declare(strict_types=1);

namespace App\Async;

/** Async — the recipient loop is O(members) and must not run in the send request. */
final class DispatchMessageNotificationsMessage
{
    public function __construct(public readonly string $messageId)
    {
    }
}
