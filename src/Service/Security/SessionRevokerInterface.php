<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\User;

interface SessionRevokerInterface
{
    public function revokeRefreshTokens(User $user): void;
}
