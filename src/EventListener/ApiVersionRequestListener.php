<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\RouterInterface;

// Priority must stay below the router listener's 32 — the `version` attribute only exists after routing.
#[AsEventListener(event: RequestEvent::class, priority: 24)]
final readonly class ApiVersionRequestListener
{
    public function __construct(private RouterInterface $router)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $version = $event->getRequest()->attributes->get('version');
        if (\is_string($version) && 1 === preg_match('/^v\d+$/', $version)) {
            $this->router->getContext()->setParameter('version', $version);
        }
    }
}
