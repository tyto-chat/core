<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\User;
use App\Repository\RefreshTokenRepository;

final readonly class SessionRevoker implements SessionRevokerInterface
{
    public function __construct(private RefreshTokenRepository $refreshTokenRepository)
    {
    }

    #[\Override]
    public function revokeRefreshTokens(User $user): void
    {
        $this->refreshTokenRepository->deleteAllForUsername($user->getUserIdentifier());
    }
}
