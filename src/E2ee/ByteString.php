<?php

declare(strict_types=1);

namespace App\E2ee;

final readonly class ByteString
{
    public function __construct(public string $bytes)
    {
    }
}
