<?php

declare(strict_types=1);

namespace App\Health\Check;

use App\Enum\Health\HealthStatus;
use App\Health\HealthCheckInterface;
use App\Health\HealthResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class MeilisearchHealthCheck implements HealthCheckInterface
{
    private const int TIMEOUT_SECONDS = 2;
    private const int DEGRADED_THRESHOLD_MS = 1000;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $meilisearchUrl,
    ) {
    }

    public function name(): string
    {
        return 'meilisearch';
    }

    public function check(): HealthResult
    {
        $start = microtime(true);

        try {
            $response = $this->httpClient->request('GET', rtrim($this->meilisearchUrl, '/').'/health', [
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $statusCode = $response->getStatusCode();
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            if (200 !== $statusCode) {
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
