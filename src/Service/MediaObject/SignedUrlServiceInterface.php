<?php

declare(strict_types=1);

namespace App\Service\MediaObject;

interface SignedUrlServiceInterface
{
    public function sign(string $relativePath, ?int $ttl = null): string;

    /** Deterministic per-bucket token; remaining validity always > $ttl at mint — safe to embed in payloads cached up to $ttl. */
    public function signStable(string $relativePath, int $ttl): string;

    public function verify(string $relativePath, string $token): bool;
}
