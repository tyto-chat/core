<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\Presence\PresenceServiceInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

// Priority must stay below the firewall (8) — earlier and Security has no user, silently breaking presence.
#[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
final readonly class PresenceTrackingListener
{
    public function __construct(
        private Security $security,
        private PresenceServiceInterface $presenceService,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $this->presenceService->touch($user);
    }
}
