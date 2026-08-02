<?php

declare(strict_types=1);

namespace App\Tests\Integration\Presence;

use App\Entity\Community;
use App\Service\Presence\RedisGuestPresenceService;
use PHPUnit\Framework\TestCase;
use Predis\Client;

class RedisGuestPresenceServiceTest extends TestCase
{
    private const COMMUNITY_ID = 990101;

    private Client $redis;
    private RedisGuestPresenceService $service;
    private Community $community;

    #[\Override]
    protected function setUp(): void
    {
        $dsn = $_ENV['REDIS_DSN'] ?? $_SERVER['REDIS_DSN'] ?? null;
        self::assertIsString($dsn, 'REDIS_DSN must be set for integration tests.');

        $this->redis = new Client($dsn, ['exceptions' => true]);
        $this->redis->del(sprintf('presence:guests:%d', self::COMMUNITY_ID));

        $this->service = new RedisGuestPresenceService($this->redis);
        $this->community = new Community();
        $ref = new \ReflectionProperty(Community::class, 'id');
        $ref->setValue($this->community, self::COMMUNITY_ID);
    }

    public function testDistinctVisitorsAreCounted(): void
    {
        $this->service->touch($this->community, 'visitor-a');
        $this->service->touch($this->community, 'visitor-b');

        self::assertSame(2, $this->service->getGuestCount($this->community));
    }

    public function testSameVisitorCountsOnce(): void
    {
        $this->service->touch($this->community, 'visitor-a');
        $this->service->touch($this->community, 'visitor-a');

        self::assertSame(1, $this->service->getGuestCount($this->community));
    }

    public function testStaleVisitorsFallOutOfTheWindow(): void
    {
        $key = sprintf('presence:guests:%d', self::COMMUNITY_ID);
        $this->redis->zadd($key, ['visitor-old' => time() - 3600]);
        $this->service->touch($this->community, 'visitor-fresh');

        self::assertSame(1, $this->service->getGuestCount($this->community));
        self::assertSame(0, (int) $this->redis->zscore($key, 'visitor-old'));
    }
}
