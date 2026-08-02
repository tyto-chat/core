<?php

declare(strict_types=1);

namespace App\Health;

use App\Enum\Health\HealthStatus;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Implementations must not throw — map every failure to a HealthStatus::Down result. */
#[AutoconfigureTag('app.health_check')]
interface HealthCheckInterface
{
    public function name(): string;

    public function check(): HealthResult;
}
