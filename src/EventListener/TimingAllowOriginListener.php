<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

// Runs after nelmio_cors (priority 0): mirrors the CORS origin so browsers expose nextHopProtocol to the client's HTTP/1.1-proxy warning.
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -1)]
final class TimingAllowOriginListener
{
    public function __invoke(ResponseEvent $event): void
    {
        $headers = $event->getResponse()->headers;
        $origin = $headers->get('Access-Control-Allow-Origin');
        if (null === $origin || $headers->has('Timing-Allow-Origin')) {
            return;
        }

        $headers->set('Timing-Allow-Origin', $origin);
    }
}
