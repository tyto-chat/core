<?php

declare(strict_types=1);

namespace App\Exception;

interface ErrorDetailsInterface
{
    /** @return array<string, scalar> merged into the JSON error body next to `error` */
    public function errorDetails(): array;
}
