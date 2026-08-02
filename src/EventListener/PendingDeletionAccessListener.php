<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Exception\User\AccountPendingDeletionException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

// Priority must stay between the firewall (8) and PresenceTrackingListener (0) — a locked account must not refresh its presence liveness key.
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
class PendingDeletionAccessListener
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if (!str_starts_with($path, '/api/')) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || !$user->isPendingDeletion()) {
            return;
        }

        if (self::isExactlyMeOrUnderAccountDeletion($path)) {
            return;
        }

        throw new AccountPendingDeletionException('Account is scheduled for deletion.');
    }

    private static function isExactlyMeOrUnderAccountDeletion(string $path): bool
    {
        if (1 === preg_match('#^/api/v\d+/me$#', $path)) {
            return true;
        }
        if (1 === preg_match('#^/api/v\d+/me/account-deletion#', $path)) {
            return true;
        }
        // AuthContext fires this unconditionally on login; the token is harmless during grace and allowing it avoids a visible 423.
        if (1 === preg_match('#^/api/v\d+/realtime/token$#', $path)) {
            return true;
        }

        return false;
    }
}
