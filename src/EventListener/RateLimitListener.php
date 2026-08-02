<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Http\RateLimitResponseFactory;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

// Priorities straddle the firewall (8): pre-auth (20) must key by IP; write (4) must key by the firewall-verified user, never a self-declared JWT claim (forged payloads rotate buckets).
#[AsEventListener(event: KernelEvents::REQUEST, priority: 20, method: 'onPreAuthRequest')]
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4, method: 'onWriteRequest')]
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4, method: 'onReadRequest')]
final readonly class RateLimitListener
{
    private const EXEMPT_ROUTES = [
        'api_voice_webhook',
    ];

    private const AUTH_LOGIN_ROUTES = [
        'auth',
        'two_factor_verify',
    ];

    /**
     * Credential-guessing routes get the tight login budget; these are the
     * session-keeping routes an already-authenticated client calls on its own
     * schedule. Sharing one IP-keyed bucket logged people out mid-session — a
     * few reloads, or a couple of colleagues behind one NAT, exhausted it.
     */
    private const SESSION_REFRESH_ROUTES = [
        'token_refresh',
        'logout',
    ];

    public function __construct(
        private RateLimiterFactoryInterface $authLoginLimiter,
        private RateLimiterFactoryInterface $sessionRefreshLimiter,
        private RateLimiterFactoryInterface $authRegisterLimiter,
        private RateLimiterFactoryInterface $apiWriteLimiter,
        private RateLimiterFactoryInterface $attachmentUploadLimiter,
        private RateLimiterFactoryInterface $messageSendLimiter,
        private RateLimiterFactoryInterface $passwordResetLimiter,
        private RateLimiterFactoryInterface $searchLimiter,
        private RateLimiterFactoryInterface $reportLimiter,
        private Security $security,
        private RateLimitResponseFactory $rateLimitResponses,
        private bool $enabled = true,
    ) {
    }

    public function onPreAuthRequest(RequestEvent $event): void
    {
        if (!$this->applies($event)) {
            return;
        }

        $request = $event->getRequest();
        $routeName = (string) $request->attributes->get('_route', '');
        $ip = $request->getClientIp() ?? '0.0.0.0';

        if (in_array($routeName, self::AUTH_LOGIN_ROUTES, true)) {
            $this->enforce($event, $this->authLoginLimiter, 'login-'.$ip);

            return;
        }

        if (in_array($routeName, self::SESSION_REFRESH_ROUTES, true)) {
            $this->enforce($event, $this->sessionRefreshLimiter, 'session-refresh-'.$ip);

            return;
        }

        if ($this->isRegistrationRoute($routeName, $request)) {
            $this->enforce($event, $this->authRegisterLimiter, 'register-'.$ip);

            return;
        }

        if (str_ends_with($routeName, '/reset_password_post')) {
            $emailKey = strtolower(trim((string) ($this->decodeJson($request)['email'] ?? '')));
            $this->enforce($event, $this->passwordResetLimiter, 'reset-'.$ip.'-'.sha1($emailKey));
        }
    }

    public function onWriteRequest(RequestEvent $event): void
    {
        if (!$this->applies($event)) {
            return;
        }

        $request = $event->getRequest();
        $routeName = (string) $request->attributes->get('_route', '');

        if (in_array($routeName, self::EXEMPT_ROUTES, true)
            || in_array($routeName, self::AUTH_LOGIN_ROUTES, true)
            || in_array($routeName, self::SESSION_REFRESH_ROUTES, true)
            || $this->isRegistrationRoute($routeName, $request)
            || str_ends_with($routeName, '/reset_password_post')
        ) {
            return;
        }

        $ip = $request->getClientIp() ?? '0.0.0.0';

        if (str_contains($routeName, '/attachments_post')) {
            $this->enforce($event, $this->attachmentUploadLimiter, 'attachment-'.$this->resolveUserKey($ip));

            return;
        }

        if (str_ends_with($routeName, '/messages_post') || str_ends_with($routeName, '/replies_post')) {
            $this->enforce($event, $this->messageSendLimiter, 'message-'.$this->resolveUserKey($ip));

            return;
        }

        if (str_ends_with($routeName, '/reports_post')) {
            $this->enforce($event, $this->reportLimiter, 'report-'.$this->resolveUserKey($ip));

            return;
        }

        if (str_starts_with($routeName, '_api_/') || str_starts_with($routeName, 'api_')) {
            $this->enforce($event, $this->apiWriteLimiter, 'write-'.$this->resolveUserKey($ip));
        }
    }

    public function onReadRequest(RequestEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (Request::METHOD_GET !== $request->getMethod()) {
            return;
        }

        if (!str_ends_with((string) $request->attributes->get('_route', ''), '/search_get')) {
            return;
        }

        $ip = $request->getClientIp() ?? '0.0.0.0';
        $this->enforce($event, $this->searchLimiter, 'search-'.$this->resolveUserKey($ip));
    }

    private function applies(RequestEvent $event): bool
    {
        if (!$this->enabled || !$event->isMainRequest()) {
            return false;
        }

        return !in_array($event->getRequest()->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    private function isRegistrationRoute(string $routeName, Request $request): bool
    {
        return '_api_/users{._format}_post' === $routeName && Request::METHOD_POST === $request->getMethod();
    }

    /** @return array<string, mixed> */
    private function decodeJson(Request $request): array
    {
        try {
            $decoded = json_decode((string) $request->getContent(), true, 8, \JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    private function enforce(RequestEvent $event, RateLimiterFactoryInterface $factory, string $key): void
    {
        $rateLimit = $factory->create($key)->consume();

        if ($rateLimit->isAccepted()) {
            return;
        }

        $event->stopPropagation();
        $event->setResponse($this->rateLimitResponses->tooManyRequests($rateLimit));
    }

    private function resolveUserKey(string $fallbackIp): string
    {
        $user = $this->security->getUser();

        return $user instanceof User ? 'uid-'.$user->getId() : 'ip-'.$fallbackIp;
    }
}
