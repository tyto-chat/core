<?php

declare(strict_types=1);

namespace App\Tests\Integration\Health;

use App\Enum\Health\HealthStatus;
use App\Health\Check\RedisHealthCheck;
use PHPUnit\Framework\TestCase;
use Predis\Client;

class RedisHealthCheckTest extends TestCase
{
    public function testReportsOkAgainstARealRedis(): void
    {
        $dsn = $_ENV['REDIS_DSN'] ?? $_SERVER['REDIS_DSN'] ?? null;
        self::assertIsString($dsn, 'REDIS_DSN must be set for integration tests.');

        $result = new RedisHealthCheck(new Client($dsn, ['exceptions' => true]))->check();

        self::assertSame('redis', $result->name);
        self::assertSame(HealthStatus::Ok, $result->status);
        self::assertGreaterThanOrEqual(0, $result->latencyMs);
    }

    public function testReportsDownWhenRedisIsUnreachable(): void
    {
        $unreachable = new Client('tcp://127.0.0.1:6390?timeout=0.2', ['exceptions' => true]);

        $result = new RedisHealthCheck($unreachable)->check();

        self::assertSame(HealthStatus::Down, $result->status);
        self::assertNotNull($result->error);
    }
}
