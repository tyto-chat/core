<?php

declare(strict_types=1);

namespace App\Tests\Unit\Health\Check;

use App\Enum\Health\HealthStatus;
use App\Health\Check\RedisHealthCheck;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;

#[AllowMockObjectsWithoutExpectations]
class RedisHealthCheckTest extends TestCase
{
    public function testReturnsOkOnPong(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('__call')->willReturn('PONG');

        $result = new RedisHealthCheck($client)->check();

        self::assertSame('redis', $result->name);
        self::assertSame(HealthStatus::Ok, $result->status);
    }

    public function testReturnsDownOnUnexpectedResponse(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('__call')->willReturn('NOTPONG');

        $result = new RedisHealthCheck($client)->check();

        self::assertSame(HealthStatus::Down, $result->status);
    }

    public function testReturnsDownOnException(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('__call')->willThrowException(new \RuntimeException('refused'));

        $result = new RedisHealthCheck($client)->check();

        self::assertSame(HealthStatus::Down, $result->status);
        self::assertSame('refused', $result->error);
    }
}
