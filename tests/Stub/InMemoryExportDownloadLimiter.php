<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Service\Gdpr\ExportDownloadLimiter;
use App\Service\Gdpr\ExportDownloadLimiterInterface;

/**
 * Functional test stub. Mirrors the cap semantics of ExportDownloadLimiter
 * without requiring a Redis connection. Per-token counters live in-process
 * for the test lifetime.
 */
class InMemoryExportDownloadLimiter implements ExportDownloadLimiterInterface
{
    /** @var array<string, int> */
    private array $counters = [];

    public function incrementAndCheck(string $token, int $ttlSeconds): bool
    {
        $key = hash('sha256', $token);
        $this->counters[$key] = ($this->counters[$key] ?? 0) + 1;

        return $this->counters[$key] <= ExportDownloadLimiter::MAX_DOWNLOADS;
    }

    public function reset(): void
    {
        $this->counters = [];
    }
}
