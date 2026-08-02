<?php

declare(strict_types=1);

namespace App\Async;

/** Used for hard deletes where the entity is gone — the id is captured up front. */
final class RemoveMessageDocumentMessage
{
    public function __construct(public readonly string $messageId)
    {
    }
}
