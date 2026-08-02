<?php

declare(strict_types=1);

namespace App\Tests\Integration\Gdpr;

use App\Service\Gdpr\ExportDownloadLimiter;
use PHPUnit\Framework\TestCase;
use Predis\Client;

class ExportDownloadLimiterTest extends TestCase
{
    private const TOKEN = 'itest-export-token';
    private const OTHER_TOKEN = 'itest-export-token-other';
    private const TTL = 300;

    private Client $redis;
    private ExportDownloadLimiter $limiter;

    #[\Override]
    protected function setUp(): void
    {
        $dsn = $_ENV['REDIS_DSN'] ?? $_SERVER['REDIS_DSN'] ?? null;
        self::assertIsString($dsn, 'REDIS_DSN must be set for integration tests.');

        $this->redis = new Client($dsn, ['exceptions' => true]);
        $this->flushKeys();
        $this->limiter = new ExportDownloadLimiter($this->redis);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->flushKeys();
    }

    private function flushKeys(): void
    {
        $this->redis->del([self::keyFor(self::TOKEN), self::keyFor(self::OTHER_TOKEN)]);
    }

    private static function keyFor(string $token): string
    {
        return 'export_dl:'.hash('sha256', $token);
    }

    public function testAllowsExactlyMaxDownloadsThenDenies(): void
    {
        for ($i = 1; $i <= ExportDownloadLimiter::MAX_DOWNLOADS; ++$i) {
            self::assertTrue($this->limiter->incrementAndCheck(self::TOKEN, self::TTL), "download $i");
        }

        self::assertFalse($this->limiter->incrementAndCheck(self::TOKEN, self::TTL));
        self::assertFalse($this->limiter->incrementAndCheck(self::TOKEN, self::TTL), 'stays denied');
    }

    public function testTtlIsSetOnFirstHitOnly(): void
    {
        $this->limiter->incrementAndCheck(self::TOKEN, self::TTL);
        $firstTtl = (int) $this->redis->ttl(self::keyFor(self::TOKEN));
        self::assertGreaterThan(0, $firstTtl);
        self::assertLessThanOrEqual(self::TTL, $firstTtl);

        $this->redis->expire(self::keyFor(self::TOKEN), 5);
        $this->limiter->incrementAndCheck(self::TOKEN, self::TTL);

        self::assertLessThanOrEqual(
            5,
            (int) $this->redis->ttl(self::keyFor(self::TOKEN)),
            'Later hits must not refresh the TTL — that would let an attacker keep the key alive.',
        );
    }

    public function testTokensAreCountedIndependently(): void
    {
        for ($i = 0; $i <= ExportDownloadLimiter::MAX_DOWNLOADS; ++$i) {
            $this->limiter->incrementAndCheck(self::TOKEN, self::TTL);
        }

        self::assertFalse($this->limiter->incrementAndCheck(self::TOKEN, self::TTL));
        self::assertTrue($this->limiter->incrementAndCheck(self::OTHER_TOKEN, self::TTL));
    }

    public function testRawTokenNeverAppearsInRedis(): void
    {
        $this->limiter->incrementAndCheck(self::TOKEN, self::TTL);

        self::assertSame(0, (int) $this->redis->exists('export_dl:'.self::TOKEN));
        self::assertSame(1, (int) $this->redis->exists(self::keyFor(self::TOKEN)));
    }
}
