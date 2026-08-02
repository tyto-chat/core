<?php

declare(strict_types=1);

namespace App\Controller;

use App\EventListener\RememberMeListener;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LogoutController extends AbstractController
{
    public function __construct(
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
    ) {
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        $tokenString = $request->cookies->get('refresh_token');
        if ($tokenString) {
            $token = $this->refreshTokenManager->get($tokenString);
            if ($token) {
                $this->refreshTokenManager->delete($token);
            }
        }

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        $response->headers->clearCookie('refresh_token', '/', null, true, true, 'none');
        $response->headers->clearCookie('BEARER', '/', null, true, true, 'none');
        $response->headers->clearCookie(RememberMeListener::REMEMBER_ME_COOKIE, '/', null, true, true, 'none');

        return $response;
    }
}
