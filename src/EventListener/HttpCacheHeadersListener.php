<?php

declare(strict_types=1);

namespace App\EventListener;

use ApiPlatform\Metadata\HttpOperation;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::RESPONSE)]
final class HttpCacheHeadersListener
{
    public function __construct(private readonly SettingsServiceInterface $settings)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $operation = $event->getRequest()->attributes->get('_api_operation');
        $flag = $operation instanceof HttpOperation
            ? ($operation->getExtraProperties()['tyto_http_cache'] ?? false)
            : false;
        if (false === $flag) {
            return;
        }

        $response = $event->getResponse();
        if (!$response->isSuccessful()) {
            return;
        }

        if (null !== $event->getRequest()->getQueryString()) {
            // Souin's auto Surrogate-Key is the bare path — query-string variants are purge-invisible and must never be stored.
            $response->headers->set('Cache-Control', 'no-store, private');

            return;
        }

        if ('presence' === $flag) {
            // Presence payloads carry no signed media URLs, so no mediaTokenTtl clamp.
            $ttl = $this->settings->get(Settings::httpCachePresenceTtlSeconds());
        } else {
            $ttl = $this->settings->get(Settings::httpCachePageTtlSeconds());
            // TTL above mediaTokenTtlSeconds would serve cached pages with dead attachment links.
            $ttl = min($ttl, $this->settings->get(Settings::mediaTokenTtlSeconds()));
        }

        if ($ttl <= 0) {
            // ttl=0 needs an explicit response no-store — Souin's bypass_request skips only request directives and would still store via its own default TTL.
            $response->headers->set('Cache-Control', 'no-store, private');

            return;
        }

        $response->setPublic();
        $response->headers->addCacheControlDirective('s-maxage', (string) $ttl);
        $response->setVary(['X-User-Context-Hash'], false);
    }
}
