<?php

declare(strict_types=1);

namespace App\Health;

use App\Enum\Health\HealthStatus;

final readonly class HealthResult
{
    public function __construct(
        public string $name,
        public HealthStatus $status,
        public int $latencyMs,
        public ?string $error = null,
    ) {
    }

    /**
     * @return array{name: string, status: string, latencyMs: int, error: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status->value,
            'latencyMs' => $this->latencyMs,
            'error' => $this->error,
        ];
    }
}
