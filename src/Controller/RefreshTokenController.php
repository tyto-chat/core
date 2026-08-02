<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

class RefreshTokenController
{
    // Route exists only so RouterListener matches; the refresh firewall's authenticator intercepts before this body runs.
    #[Route('/token/refresh', name: 'token_refresh', methods: ['POST'])]
    public function refresh(): never
    {
        throw new UnauthorizedHttpException('Bearer', 'Invalid refresh token.');
    }
}
