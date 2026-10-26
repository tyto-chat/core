<?php

declare(strict_types=1);

namespace App\Tests\Integration\HttpCache;

use App\Service\HttpCache\SouinRedisCacheFlusher;
use PHPUnit\Framework\TestCase;
use Predis\Client;

class SouinRedisCacheFlusherTest extends TestCase
{
    private const array KEPT = ['presence:user:901:live', 'http_cache:quarantine:k901', 'WpKZ5BvrMe:scheduler_checkpoint_default', 'flusher-test:GET-http-not-souin', '3f45a:symfony-cache-item', '901:counter'];

    private Client $redis;

    #[\Override]
    protected function setUp(): void
    {
        $dsn = $_ENV['REDIS_DSN'] ?? $_SERVER['REDIS_DSN'] ?? null;
        self::assertIsString($dsn, 'REDIS_DSN must be set for integration tests.');

        $this->redis = new Client($dsn, ['exceptions' => true]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->redis->del(self::KEPT);
    }

    public function testDeletesEverySouinKeyAndNothingElse(): void
    {
        $souinKeys = [
            'GET-http-localhost:8091-/api/v1/communities/c/emojis{-VARY-}Accept:application/ld+json;X-User-Context-Hash:v2%3Aanon',
            'GET-https-tyto.chat-/api/v1/communities/c/channels/g/pages/1',
            'HEAD-http-localhost:8091-/api/v1/communities/c',
            'IDX_GET-http-localhost:8091-/api/v1/communities/c/emojis',
            'SURROGATE_pages-k901',
            'SURROGATE_ctx-u901',
            '9679209279358730877',
            'IDX_9679209279358730877',
        ];
        for ($i = 0; $i < 1500; ++$i) {
            $souinKeys[] = 'GET-http-localhost:8091-/api/v1/messages/'.$i;
        }
        foreach ([...$souinKeys, ...self::KEPT] as $key) {
            $this->redis->set($key, '1');
        }

        $removed = (new SouinRedisCacheFlusher($this->redis))->flushAll();

        self::assertGreaterThanOrEqual(\count($souinKeys), $removed);
        self::assertSame(0, $this->existing($souinKeys));
        self::assertSame(\count(self::KEPT), $this->existing(self::KEPT));
    }

    /** @param list<string> $keys */
    private function existing(array $keys): int
    {
        return array_sum(array_map(fn (string $key): int => (int) $this->redis->exists($key), $keys));
    }
}
