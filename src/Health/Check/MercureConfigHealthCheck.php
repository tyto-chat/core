<?php

declare(strict_types=1);

namespace App\Health\Check;

use App\Enum\Health\HealthStatus;
use App\Health\HealthCheckInterface;
use App\Health\HealthResult;
use App\Service\Admin\ConfigStatusServiceInterface;

final readonly class MercureConfigHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private ConfigStatusServiceInterface $configStatus,
    ) {
    }

    public function name(): string
    {
        return 'mercure-config';
    }

    public function check(): HealthResult
    {
        if ($this->configStatus->isMercureConfigured()) {
            return new HealthResult($this->name(), HealthStatus::Ok, 0);
        }

        return new HealthResult($this->name(), HealthStatus::Degraded, 0, 'Mercure URL is a placeholder — real-time features are disabled.');
    }
}
