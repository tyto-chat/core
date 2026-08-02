<?php

declare(strict_types=1);

namespace App\Service\Admin;

interface TestEmailServiceInterface
{
    // Returns null on success, the transport error message on failure.
    public function send(string $to): ?string;
}
