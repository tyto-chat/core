<?php

declare(strict_types=1);

namespace App\Dto\User;

final readonly class TwoFactorSetupDto
{
    public function __construct(
        public string $secret = '',
        public string $otpauthUri = '',
    ) {
    }
}
