<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Below the router's 32 so the route name is resolved.
#[AsEventListener(event: RequestEvent::class, priority: 24)]
final readonly class ApiDocsGateListener
{
    public function __construct(
        #[Autowire(env: 'bool:API_DOCS_ENABLED')]
        private bool $docsEnabled,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if ($this->docsEnabled || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route');

        if ('api_doc' === $route) {
            throw new NotFoundHttpException();
        }

        // The entrypoint stays available as machine-readable JSON-LD; only the
        // human docs UI (HTML negotiation) is gated.
        if ('api_entrypoint' === $route && str_contains((string) $request->headers->get('Accept'), 'text/html')) {
            throw new NotFoundHttpException();
        }
    }
}
