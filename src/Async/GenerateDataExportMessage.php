<?php

declare(strict_types=1);

namespace App\Async;

final readonly class GenerateDataExportMessage
{
    public function __construct(
        public int $requestId,
    ) {
    }
}
