<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::RESPONSE)]
final class RefreshTokenTransportListener
{
    private const string REFRESH_TOKEN_FIELD = 'refresh_token';
    private const string TRANSPORT_HEADER = 'X-Token-Transport';
    private const array TRANSPORT_ROUTES = ['auth', 'two_factor_verify', 'token_refresh'];

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!in_array($request->attributes->get('_route'), self::TRANSPORT_ROUTES, true)) {
            return;
        }

        $response = $event->getResponse();
        if (!$response->isSuccessful()) {
            return;
        }

        $bodyTransportRequested = 'body' === $request->headers->get(self::TRANSPORT_HEADER);
        if ($bodyTransportRequested && 'token_refresh' === $request->attributes->get('_route') && $request->cookies->has(self::REFRESH_TOKEN_FIELD)) {
            $bodyTransportRequested = false;
        }

        if ($bodyTransportRequested) {
            $this->stripRefreshTokenCookie($response);

            return;
        }

        $this->stripRefreshTokenFromBody($response);
    }

    private function stripRefreshTokenCookie(Response $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if (self::REFRESH_TOKEN_FIELD === $cookie->getName()) {
                $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());

                return;
            }
        }
    }

    private function stripRefreshTokenFromBody(Response $response): void
    {
        $content = $response->getContent();
        if (!is_string($content) || '' === $content) {
            return;
        }

        try {
            $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        if (!is_array($data) || !array_key_exists(self::REFRESH_TOKEN_FIELD, $data)) {
            return;
        }

        unset($data[self::REFRESH_TOKEN_FIELD]);
        $response->setContent((string) json_encode($data, JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_THROW_ON_ERROR));
    }
}
