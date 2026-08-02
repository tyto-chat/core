<?php

declare(strict_types=1);

namespace App\Tests\Unit\Health;

use App\Enum\Health\HealthStatus;
use App\Health\Check\MeiliConfigHealthCheck;
use App\Health\Check\MercureConfigHealthCheck;
use App\Service\Admin\ConfigStatusServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class ConfigHealthCheckTest extends TestCase
{
    public function testMercureOkWhenConfigured(): void
    {
        $cfg = $this->createMock(ConfigStatusServiceInterface::class);
        $cfg->method('isMercureConfigured')->willReturn(true);
        $result = (new MercureConfigHealthCheck($cfg))->check();
        self::assertSame('mercure-config', $result->name);
        self::assertSame(HealthStatus::Ok, $result->status);
    }

    public function testMercureDegradedWhenUnconfigured(): void
    {
        $cfg = $this->createMock(ConfigStatusServiceInterface::class);
        $cfg->method('isMercureConfigured')->willReturn(false);
        $result = (new MercureConfigHealthCheck($cfg))->check();
        self::assertSame(HealthStatus::Degraded, $result->status);
        self::assertNotNull($result->error);
    }

    public function testMeiliOkWhenConfigured(): void
    {
        $cfg = $this->createMock(ConfigStatusServiceInterface::class);
        $cfg->method('isMeiliConfigured')->willReturn(true);
        $result = (new MeiliConfigHealthCheck($cfg))->check();
        self::assertSame('meilisearch-config', $result->name);
        self::assertSame(HealthStatus::Ok, $result->status);
    }

    public function testMeiliDegradedWhenUnconfigured(): void
    {
        $cfg = $this->createMock(ConfigStatusServiceInterface::class);
        $cfg->method('isMeiliConfigured')->willReturn(false);
        $result = (new MeiliConfigHealthCheck($cfg))->check();
        self::assertSame(HealthStatus::Degraded, $result->status);
    }
}
