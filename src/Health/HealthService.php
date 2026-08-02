<?php

declare(strict_types=1);

namespace App\Health;

use App\Enum\Health\HealthStatus;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class HealthService
{
    /**
     * @param iterable<HealthCheckInterface> $checks
     */
    public function __construct(
        #[AutowireIterator('app.health_check')]
        private iterable $checks,
    ) {
    }

    /**
     * @return list<HealthResult>
     */
    public function runAll(): array
    {
        $results = [];
        foreach ($this->checks as $check) {
            try {
                $results[] = $check->check();
            } catch (\Throwable $e) {
                $results[] = new HealthResult(
                    name: $check->name(),
                    status: HealthStatus::Down,
                    latencyMs: 0,
                    error: 'Probe threw: '.substr($e->getMessage(), 0, 200),
                );
            }
        }

        return $results;
    }

    /**
     * @param list<HealthResult> $results
     */
    public function overall(array $results): HealthStatus
    {
        return HealthStatus::worstOf(array_map(static fn (HealthResult $r) => $r->status, $results));
    }
}
