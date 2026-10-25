<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use ApiPlatform\Metadata\Get;
use App\EventListener\HttpCacheHeadersListener;
use App\Security\Authentication\ApiKeyAuthenticator;
use App\Service\HttpCache\CacheTagCollector;
use App\Service\HttpCache\HttpCacheSwitchInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class HttpCacheHeadersListenerTest extends TestCase
{
    private function respond(bool $switchOn, bool $viaPat, int $pageTtl): Response
    {
        $settings = self::createStub(SettingsServiceInterface::class);
        $settings->method('get')->willReturnCallback(static fn ($def): int => match ($def->key) {
            Settings::httpCachePageTtlSeconds()->key => $pageTtl,
            Settings::mediaTokenTtlSeconds()->key => 3600,
            default => 0,
        });
        $switch = self::createStub(HttpCacheSwitchInterface::class);
        $switch->method('isOn')->willReturn($switchOn);

        $request = Request::create('/api/v1/communities/c/emojis');
        $request->attributes->set('_api_operation', new Get(extraProperties: ['tyto_http_cache' => true]));
        if ($viaPat) {
            $request->attributes->set(ApiKeyAuthenticator::REQUEST_SCOPES_ATTRIBUTE, ['communities:read']);
        }

        $response = new Response('{}');
        $event = new ResponseEvent(self::createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);
        (new HttpCacheHeadersListener($settings, $switch, new CacheTagCollector()))($event);

        return $response;
    }

    public function testPublicWhenSwitchOnAndTtlSet(): void
    {
        $response = $this->respond(true, false, 30);

        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('30', $response->headers->getCacheControlDirective('s-maxage'));
    }

    public function testNoStoreWhenSwitchOff(): void
    {
        $response = $this->respond(false, false, 30);

        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    public function testNoStoreForPatCallers(): void
    {
        $response = $this->respond(true, true, 30);

        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    public function testNoStoreWhenTtlZero(): void
    {
        $response = $this->respond(true, false, 0);

        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }
}
