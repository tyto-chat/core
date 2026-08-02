<?php

declare(strict_types=1);

namespace App\Exception;

interface HttpHeadersAwareExceptionInterface
{
    /** @return array<string, string> */
    public function httpHeaders(): array;
}
