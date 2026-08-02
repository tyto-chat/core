<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
final class CookieAuthCsrfListener
{
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return;
        }
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }
        if (!$request->cookies->has('BEARER') || $request->headers->has('Authorization')) {
            return;
        }

        throw new AccessDeniedHttpException('Cookie-authenticated state-changing requests are not allowed; use the Authorization header.');
    }
}
