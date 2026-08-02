<?php

declare(strict_types=1);

namespace App\Health\Check;

use App\Enum\Health\HealthStatus;
use App\Health\HealthCheckInterface;
use App\Health\HealthResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class MercureHealthCheck implements HealthCheckInterface
{
    private const int TIMEOUT_SECONDS = 2;
    private const int DEGRADED_THRESHOLD_MS = 1000;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $mercureInternalUrl,
    ) {
    }

    public function name(): string
    {
        return 'mercure';
    }

    public function check(): HealthResult
    {
        $start = microtime(true);

        try {
            $response = $this->httpClient->request('GET', $this->mercureInternalUrl, [
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $statusCode = $response->getStatusCode();
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            // 401/405/200 all mean the hub is alive — only 5xx signals trouble.
            if ($statusCode >= 500) {
                return new HealthResult($this->name(), HealthStatus::Down, $latencyMs, sprintf('HTTP %d', $statusCode));
            }

            $status = $latencyMs > self::DEGRADED_THRESHOLD_MS ? HealthStatus::Degraded : HealthStatus::Ok;

            return new HealthResult($this->name(), $status, $latencyMs);
        } catch (\Throwable $e) {
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            return new HealthResult($this->name(), HealthStatus::Down, $latencyMs, substr($e->getMessage(), 0, 200));
        }
    }
}
