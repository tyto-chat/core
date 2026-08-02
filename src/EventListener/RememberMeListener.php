<?php

declare(strict_types=1);

namespace App\EventListener;

use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;

// /token/refresh bodies are empty — reading remember_me from them would silently downgrade every rotated cookie to a session cookie, hence the companion cookie.
#[AsEventListener(event: Events::AUTHENTICATION_SUCCESS, priority: -10)]
class RememberMeListener
{
    public const REMEMBER_ME_COOKIE = 'remember_me';

    public function __construct(
        private readonly RequestStack $requestStack,
        #[Autowire('%gesdinet_jwt_refresh_token.ttl%')]
        private readonly int $refreshTokenTtl,
    ) {
    }

    public function __invoke(AuthenticationSuccessEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $response = $event->getResponse();

        if ('/token/refresh' === $request?->getPathInfo()) {
            $rememberMe = null !== $request->cookies->get(self::REMEMBER_ME_COOKIE);
        } else {
            try {
                $body = json_decode($request?->getContent() ?: '{}', true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $body = [];
            }
            $rememberMe = is_array($body) && (bool) ($body['remember_me'] ?? false);
        }

        if ($rememberMe) {
            $response->headers->setCookie(new Cookie(
                name: self::REMEMBER_ME_COOKIE,
                value: '1',
                expire: time() + $this->refreshTokenTtl,
                path: '/',
                secure: true,
                httpOnly: true,
                sameSite: 'none',
            ));

            return;
        }

        $response->headers->clearCookie(self::REMEMBER_ME_COOKIE, '/', null, true, true, 'none');
        foreach ($response->headers->getCookies() as $cookie) {
            if ('refresh_token' !== $cookie->getName()) {
                continue;
            }
            $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            $response->headers->setCookie(new Cookie(
                name: $cookie->getName(),
                value: $cookie->getValue(),
                expire: 0,
                path: $cookie->getPath(),
                domain: $cookie->getDomain(),
                secure: $cookie->isSecure(),
                httpOnly: $cookie->isHttpOnly(),
                raw: $cookie->isRaw(),
                sameSite: $cookie->getSameSite(),
                partitioned: $cookie->isPartitioned(),
            ));
        }
    }
}
