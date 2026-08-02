<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Dto\User\TwoFactorRecoveryCodesDto;
use App\Dto\User\TwoFactorSetupDto;
use App\Dto\User\TwoFactorStatusDto;
use App\Entity\User;

interface TwoFactorServiceInterface
{
    public function status(): TwoFactorStatusDto;

    public function setup(): TwoFactorSetupDto;

    public function confirm(string $code): TwoFactorRecoveryCodesDto;

    public function disable(string $currentPassword): void;

    /** @internal No authz — CLI/admin-trusted wipe chokepoint. */
    public function reset(User $user): void;

    public function regenerateRecoveryCodes(string $currentPassword): TwoFactorRecoveryCodesDto;

    public function verifyLogin(User $user, string $code): void;
}
