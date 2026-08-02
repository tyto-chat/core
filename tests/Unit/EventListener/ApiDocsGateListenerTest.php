<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\ApiDocsGateListener;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[AllowMockObjectsWithoutExpectations]
class ApiDocsGateListenerTest extends TestCase
{
    private function dispatch(bool $enabled, string $route, string $accept): void
    {
        $request = Request::create('/api/v1/docs');
        $request->attributes->set('_route', $route);
        $request->headers->set('Accept', $accept);

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        new ApiDocsGateListener($enabled)($event);
    }

    public function testEnabledPassesEverything(): void
    {
        $this->dispatch(true, 'api_doc', 'text/html');
        $this->dispatch(true, 'api_entrypoint', 'text/html');

        $this->expectNotToPerformAssertions();
    }

    public function testDisabledBlocksDocsRoute(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->dispatch(false, 'api_doc', 'application/ld+json');
    }

    public function testDisabledBlocksHtmlEntrypoint(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->dispatch(false, 'api_entrypoint', 'text/html,application/xhtml+xml');
    }

    public function testDisabledKeepsJsonLdEntrypoint(): void
    {
        $this->dispatch(false, 'api_entrypoint', 'application/ld+json');
        $this->dispatch(false, 'api_messages_get', 'text/html');

        $this->expectNotToPerformAssertions();
    }
}
