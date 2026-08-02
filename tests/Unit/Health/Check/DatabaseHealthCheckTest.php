<?php

declare(strict_types=1);

namespace App\Tests\Unit\Health\Check;

use App\Enum\Health\HealthStatus;
use App\Health\Check\DatabaseHealthCheck;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DatabaseHealthCheckTest extends TestCase
{
    public function testReturnsOkWhenSelectOneSucceeds(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(1);

        $result = new DatabaseHealthCheck($connection)->check();

        self::assertSame('database', $result->name);
        self::assertSame(HealthStatus::Ok, $result->status);
        self::assertNull($result->error);
    }

    public function testReturnsDownWhenConnectionThrows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('boom'));

        $result = new DatabaseHealthCheck($connection)->check();

        self::assertSame(HealthStatus::Down, $result->status);
        self::assertSame('boom', $result->error);
    }

    public function testReturnsDownOnUnexpectedResult(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(42);

        $result = new DatabaseHealthCheck($connection)->check();

        self::assertSame(HealthStatus::Down, $result->status);
    }
}
