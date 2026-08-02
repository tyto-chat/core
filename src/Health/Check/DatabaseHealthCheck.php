<?php

declare(strict_types=1);

namespace App\Health\Check;

use App\Enum\Health\HealthStatus;
use App\Health\HealthCheckInterface;
use App\Health\HealthResult;
use Doctrine\DBAL\Connection;

final readonly class DatabaseHealthCheck implements HealthCheckInterface
{
    private const int DEGRADED_THRESHOLD_MS = 1000;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function name(): string
    {
        return 'database';
    }

    public function check(): HealthResult
    {
        $start = microtime(true);

        try {
            $value = $this->connection->fetchOne('SELECT 1');
            $latencyMs = (int) ((microtime(true) - $start) * 1000);
            if (1 !== (int) $value) {
                return new HealthResult($this->name(), HealthStatus::Down, $latencyMs, 'Unexpected SELECT 1 result.');
            }

            $status = $latencyMs > self::DEGRADED_THRESHOLD_MS ? HealthStatus::Degraded : HealthStatus::Ok;

            return new HealthResult($this->name(), $status, $latencyMs);
        } catch (\Throwable $e) {
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            return new HealthResult($this->name(), HealthStatus::Down, $latencyMs, substr($e->getMessage(), 0, 200));
        }
    }
}
