<?php

declare(strict_types=1);

namespace App\Service\Gdpr;

use Predis\ClientInterface;

class ExportDownloadLimiter implements ExportDownloadLimiterInterface
{
    public const int MAX_DOWNLOADS = 10;
    private const string KEY_PREFIX = 'export_dl:';

    public function __construct(
        private readonly ClientInterface $redis,
    ) {
    }

    public function incrementAndCheck(string $token, int $ttlSeconds): bool
    {
        $key = self::KEY_PREFIX.hash('sha256', $token);

        $count = (int) $this->redis->incr($key);
        if (1 === $count) {
            // TTL only on first increment — re-applying on every hit would let an attacker keep the key alive.
            $this->redis->expire($key, $ttlSeconds);
        }

        return $count <= self::MAX_DOWNLOADS;
    }
}
