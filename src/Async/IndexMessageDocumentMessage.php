<?php

declare(strict_types=1);

namespace App\Async;

final class IndexMessageDocumentMessage
{
    public function __construct(public readonly string $messageId)
    {
    }
}
