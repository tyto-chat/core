<?php

declare(strict_types=1);

namespace App\Service\HttpCache;

use Predis\ClientInterface;

final readonly class SouinRedisCacheFlusher implements HttpCacheFlusherInterface
{
    /** Only Souin's own keys are deleted, in case CACHE_REDIS_URL still points at the shared Valkey. */
    public const array KEY_PATTERNS = ['GET-http*', 'HEAD-http*', 'IDX_*', 'SURROGATE_*', '[0-9]*'];

    public function __construct(
        private ClientInterface $redis,
    ) {
    }

    #[\Override]
    public function flushAll(): int
    {
        $removed = 0;
        foreach (self::KEY_PATTERNS as $pattern) {
            $cursor = '0';
            do {
                /** @var array{0: string, 1: list<string>} $page */
                $page = $this->redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 1000]);
                [$cursor, $keys] = $page;
                if ('[0-9]*' === $pattern) {
                    $keys = array_values(array_filter($keys, ctype_digit(...)));
                }
                if ([] !== $keys) {
                    $removed += (int) $this->redis->del($keys);
                }
            } while ('0' !== (string) $cursor);
        }

        return $removed;
    }
}
