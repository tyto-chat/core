<?php

declare(strict_types=1);

namespace App\Tests\Unit\Health;

use App\Enum\Health\HealthStatus;
use App\Health\HealthCheckInterface;
use App\Health\HealthResult;
use App\Health\HealthService;
use PHPUnit\Framework\TestCase;

class HealthServiceTest extends TestCase
{
    public function testRunAllAggregatesResults(): void
    {
        $service = new HealthService([
            $this->stubCheck('a', HealthStatus::Ok),
            $this->stubCheck('b', HealthStatus::Ok),
        ]);

        $results = $service->runAll();

        self::assertCount(2, $results);
        self::assertSame(HealthStatus::Ok, $service->overall($results));
    }

    public function testRunAllCatchesProbeException(): void
    {
        $throwing = new class implements HealthCheckInterface {
            public function name(): string
            {
                return 'broken';
            }

            public function check(): HealthResult
            {
                throw new \RuntimeException('explode');
            }
        };

        $service = new HealthService([$throwing, $this->stubCheck('ok-one', HealthStatus::Ok)]);
        $results = $service->runAll();

        self::assertCount(2, $results);
        self::assertSame('broken', $results[0]->name);
        self::assertSame(HealthStatus::Down, $results[0]->status);
        self::assertStringContainsString('Probe threw', (string) $results[0]->error);
    }

    public function testOverallReturnsWorstStatus(): void
    {
        $service = new HealthService([
            $this->stubCheck('a', HealthStatus::Ok),
            $this->stubCheck('b', HealthStatus::Degraded),
            $this->stubCheck('c', HealthStatus::Down),
        ]);

        self::assertSame(HealthStatus::Down, $service->overall($service->runAll()));
    }

    private function stubCheck(string $name, HealthStatus $status): HealthCheckInterface
    {
        return new class($name, $status) implements HealthCheckInterface {
            public function __construct(
                private readonly string $stubName,
                private readonly HealthStatus $stubStatus,
            ) {
            }

            public function name(): string
            {
                return $this->stubName;
            }

            public function check(): HealthResult
            {
                return new HealthResult($this->stubName, $this->stubStatus, 10);
            }
        };
    }
}
