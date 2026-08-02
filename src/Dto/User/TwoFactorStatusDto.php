<?php

declare(strict_types=1);

namespace App\Dto\User;

final readonly class TwoFactorStatusDto
{
    public function __construct(
        public bool $enabled = false,
        public ?string $enabledAt = null,
        public int $recoveryCodesRemaining = 0,
    ) {
    }
}
