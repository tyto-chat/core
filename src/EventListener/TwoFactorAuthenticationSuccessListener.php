<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

/** Must stay below gesdinet attach (0) and RememberMeListener (-10) — earlier would resurrect stripped cookies. */
#[AsEventListener(event: Events::AUTHENTICATION_SUCCESS, priority: -20)]
final class TwoFactorAuthenticationSuccessListener
{
    public const string PENDING_CLAIM = '2fa_pending';
    private const int PENDING_TOKEN_TTL = 300;
    private const string REFRESH_TOKEN_COOKIE = 'refresh_token';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
    ) {
    }

    public function __invoke(AuthenticationSuccessEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ('/auth' !== $request?->getPathInfo()) {
            return;
        }
        $user = $event->getUser();
        if (!$user instanceof User || !$user->isTwoFactorEnabled()) {
            return;
        }

        $response = $event->getResponse();

        $refreshTokenCookie = null;
        foreach ($response->headers->getCookies() as $cookie) {
            if (self::REFRESH_TOKEN_COOKIE === $cookie->getName()) {
                $refreshTokenCookie = $cookie;
                break;
            }
        }
        if (null !== $refreshTokenCookie) {
            $stored = $this->refreshTokenManager->get((string) $refreshTokenCookie->getValue());
            if (null !== $stored) {
                $this->refreshTokenManager->delete($stored);
            }
            $response->headers->removeCookie(
                self::REFRESH_TOKEN_COOKIE,
                $refreshTokenCookie->getPath(),
                $refreshTokenCookie->getDomain(),
            );
        }

        $pending = $this->jwtManager->createFromPayload($user, [
            self::PENDING_CLAIM => true,
            'exp' => time() + self::PENDING_TOKEN_TTL,
        ]);

        $event->setData(['twoFactorRequired' => true, 'token' => $pending]);

        $response->headers->removeCookie('BEARER', '/');
        $response->headers->clearCookie('BEARER', '/', null, true, true, 'none');
        $response->headers->removeCookie(RememberMeListener::REMEMBER_ME_COOKIE, '/');
    }
}
