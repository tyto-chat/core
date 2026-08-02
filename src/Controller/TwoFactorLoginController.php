<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\EventListener\TwoFactorAuthenticationSuccessListener;
use App\Http\RateLimitResponseFactory;
use App\Service\User\TwoFactorServiceInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Authentication\AuthenticationSuccessHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

class TwoFactorLoginController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
        private readonly TwoFactorServiceInterface $twoFactorService,
        private readonly AuthenticationSuccessHandler $successHandler,
        private readonly RateLimiterFactoryInterface $twoFactorLimiter,
        private readonly RateLimitResponseFactory $rateLimitResponses,
    ) {
    }

    #[Route('/auth/2fa', name: 'two_factor_verify', methods: ['POST'])]
    public function verify(Request $request): Response
    {
        $token = $this->security->getToken();
        $user = $this->security->getUser();
        if (!$user instanceof User || null === $token
            || !$token->hasAttribute(TwoFactorAuthenticationSuccessListener::PENDING_CLAIM)) {
            throw new UnauthorizedHttpException('Bearer', 'A pending two-factor token is required.');
        }

        $rateLimit = $this->twoFactorLimiter->create('2fa-'.$user->getId())->consume();
        if (!$rateLimit->isAccepted()) {
            return $this->rateLimitResponses->tooManyRequests($rateLimit);
        }

        $content = $request->getContent();
        try {
            $body = json_decode('' !== $content ? $content : '{}', true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $body = [];
        }
        $code = is_array($body) ? (string) ($body['code'] ?? '') : '';

        $this->twoFactorService->verifyLogin($user, $code);

        return $this->successHandler->handleAuthenticationSuccess($user);
    }
}
