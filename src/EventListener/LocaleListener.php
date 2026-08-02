<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 30)]
final readonly class LocaleListener
{
    /** @param list<string> $enabledLocales */
    public function __construct(private array $enabledLocales)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $locale = $request->getPreferredLanguage($this->enabledLocales) ?? 'en';
        $request->setLocale($locale);
    }
}
