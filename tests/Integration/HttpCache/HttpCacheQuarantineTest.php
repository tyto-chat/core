<?php

declare(strict_types=1);

namespace App\Tests\Integration\HttpCache;

use App\Service\HttpCache\HttpCacheQuarantine;
use PHPUnit\Framework\TestCase;
use Predis\Client;

class HttpCacheQuarantineTest extends TestCase
{
    private Client $redis;
    private HttpCacheQuarantine $quarantine;

    #[\Override]
    protected function setUp(): void
    {
        $dsn = $_ENV['REDIS_DSN'] ?? $_SERVER['REDIS_DSN'] ?? null;
        self::assertIsString($dsn, 'REDIS_DSN must be set for integration tests.');

        $this->redis = new Client($dsn, ['exceptions' => true]);
        $this->redis->del([HttpCacheQuarantine::key(901), HttpCacheQuarantine::key(902), HttpCacheQuarantine::userKey(901)]);
        $this->quarantine = new HttpCacheQuarantine($this->redis);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->redis->del([HttpCacheQuarantine::key(901), HttpCacheQuarantine::key(902), HttpCacheQuarantine::userKey(901)]);
    }

    public function testQuarantinesOnlyThatCommunityForTheWindow(): void
    {
        self::assertFalse($this->quarantine->isCommunityQuarantined(901));

        $this->quarantine->quarantineCommunity(901);

        self::assertTrue($this->quarantine->isCommunityQuarantined(901));
        self::assertFalse($this->quarantine->isCommunityQuarantined(902));
        $ttl = (int) $this->redis->ttl(HttpCacheQuarantine::key(901));
        self::assertGreaterThan(HttpCacheQuarantine::WINDOW_SECONDS - 5, $ttl);
        self::assertLessThanOrEqual(HttpCacheQuarantine::WINDOW_SECONDS, $ttl);
    }

    public function testUserQuarantineIsSeparateFromCommunityQuarantine(): void
    {
        $this->quarantine->quarantineUser(901);

        self::assertTrue($this->quarantine->isUserQuarantined(901));
        self::assertFalse($this->quarantine->isCommunityQuarantined(901));
        self::assertGreaterThan(HttpCacheQuarantine::WINDOW_SECONDS - 5, (int) $this->redis->ttl(HttpCacheQuarantine::userKey(901)));
    }
}
