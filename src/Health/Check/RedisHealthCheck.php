<?php

declare(strict_types=1);

namespace App\Health\Check;

use App\Enum\Health\HealthStatus;
use App\Health\HealthCheckInterface;
use App\Health\HealthResult;
use Predis\ClientInterface;

final readonly class RedisHealthCheck implements HealthCheckInterface
{
    private const int DEGRADED_THRESHOLD_MS = 500;

    public function __construct(
        private ClientInterface $redis,
    ) {
    }

    public function name(): string
    {
        return 'redis';
    }

    public function check(): HealthResult
    {
        $start = microtime(true);

        try {
            /** @var string $pong */
            $pong = (string) $this->redis->__call('ping', []);
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            if ('PONG' !== strtoupper($pong)) {
                return new HealthResult($this->name(), HealthStatus::Down, $latencyMs, sprintf('Unexpected PING response: %s', $pong));
            }

            $status = $latencyMs > self::DEGRADED_THRESHOLD_MS ? HealthStatus::Degraded : HealthStatus::Ok;

            return new HealthResult($this->name(), $status, $latencyMs);
        } catch (\Throwable $e) {
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            return new HealthResult($this->name(), HealthStatus::Down, $latencyMs, substr($e->getMessage(), 0, 200));
        }
    }
}
