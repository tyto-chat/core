<?php

declare(strict_types=1);

namespace App\Tests\Unit\Health;

use App\Enum\Health\HealthStatus;
use PHPUnit\Framework\TestCase;

class HealthStatusTest extends TestCase
{
    public function testWorstOfEmptyDefaultsToOk(): void
    {
        self::assertSame(HealthStatus::Ok, HealthStatus::worstOf([]));
    }

    public function testWorstOfAllOkReturnsOk(): void
    {
        self::assertSame(HealthStatus::Ok, HealthStatus::worstOf([HealthStatus::Ok, HealthStatus::Ok]));
    }

    public function testWorstOfDegradedAndOk(): void
    {
        self::assertSame(
            HealthStatus::Degraded,
            HealthStatus::worstOf([HealthStatus::Ok, HealthStatus::Degraded, HealthStatus::Ok]),
        );
    }

    public function testWorstOfDownBeatsEverything(): void
    {
        self::assertSame(
            HealthStatus::Down,
            HealthStatus::worstOf([HealthStatus::Ok, HealthStatus::Degraded, HealthStatus::Down, HealthStatus::Unknown]),
        );
    }
}
