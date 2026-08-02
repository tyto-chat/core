<?php

declare(strict_types=1);

namespace App\EventListener;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: Events::JWT_AUTHENTICATED)]
final readonly class TwoFactorJwtAuthenticatedListener
{
    public function __invoke(JWTAuthenticatedEvent $event): void
    {
        $payload = $event->getPayload();
        if (true === ($payload[TwoFactorAuthenticationSuccessListener::PENDING_CLAIM] ?? false)) {
            $event->getToken()->setAttribute(TwoFactorAuthenticationSuccessListener::PENDING_CLAIM, true);
        }
    }
}
