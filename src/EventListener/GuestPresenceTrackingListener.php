<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Repository\CommunityRepository;
use App\Service\Presence\GuestPresenceServiceInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
final readonly class GuestPresenceTrackingListener
{
    public function __construct(
        private Security $security,
        private CommunityRepository $communityRepository,
        private GuestPresenceServiceInterface $guestPresence,
        #[Autowire('%kernel.secret%')] private string $secret,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        if (null !== $this->security->getUser()) {
            return;
        }
        $request = $event->getRequest();
        if (1 !== preg_match('#^/api(?:/v\d+)?/communities/([^/]+)#', $request->getPathInfo(), $m)) {
            return;
        }
        $community = $this->communityRepository->findOneBy(['identifier' => $m[1]]);
        if (null === $community || $community->isPrivate()) {
            return;
        }
        $this->guestPresence->touch($community, $this->visitorKey($request));
    }

    private function visitorKey(Request $request): string
    {
        return hash('sha256', implode('|', [
            (string) $request->getClientIp(),
            (string) $request->headers->get('User-Agent'),
            date('Y-m-d'),
            $this->secret,
        ]));
    }
}
