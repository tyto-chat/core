<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\User\TwoFactorPendingException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Priority 4 — must run after the firewall (8) populates the token. */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
class TwoFactorPendingAccessListener
{
    private const array ALLOWED_ROUTES = ['two_factor_verify', 'logout'];

    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->security->getToken();
        if (null === $token || !$token->hasAttribute(TwoFactorAuthenticationSuccessListener::PENDING_CLAIM)) {
            return;
        }

        if (in_array($event->getRequest()->attributes->get('_route'), self::ALLOWED_ROUTES, true)) {
            return;
        }

        throw new TwoFactorPendingException('Pending two-factor token used outside the verify/logout endpoints.');
    }
}
