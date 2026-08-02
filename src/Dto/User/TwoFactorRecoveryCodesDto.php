<?php

declare(strict_types=1);

namespace App\Dto\User;

final readonly class TwoFactorRecoveryCodesDto
{
    /** @param string[] $recoveryCodes */
    public function __construct(
        public array $recoveryCodes = [],
    ) {
    }
}
