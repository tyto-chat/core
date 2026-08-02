<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\Entity\User;
use App\EventListener\RateLimitListener;
use App\Http\RateLimitResponseFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class RateLimitListenerTest extends TestCase
{
    /** @var RateLimiterFactoryInterface&MockObject */
    private RateLimiterFactoryInterface $authLoginLimiter;

    /** @var RateLimiterFactoryInterface&MockObject */
    private RateLimiterFactoryInterface $sessionRefreshLimiter;

    /** @var RateLimiterFactoryInterface&MockObject */
    private RateLimiterFactoryInterface $authRegisterLimiter;

    /** @var RateLimiterFactoryInterface&MockObject */
    private RateLimiterFactoryInterface $apiWriteLimiter;

    /** @var RateLimiterFactoryInterface&MockObject */
    private RateLimiterFactoryInterface $attachmentUploadLimiter;

    /** @var RateLimiterFactoryInterface&MockObject */
    private RateLimiterFactoryInterface $messageSendLimiter;

    /** @var RateLimiterFactoryInterface&MockObject */
    private RateLimiterFactoryInterface $passwordResetLimiter;

    /** @var RateLimiterFactoryInterface&MockObject */
    private RateLimiterFactoryInterface $searchLimiter;

    /** @var RateLimiterFactoryInterface&MockObject */
    private RateLimiterFactoryInterface $reportLimiter;

    /** @var Security&MockObject */
    private Security $security;

    #[\Override]
    protected function setUp(): void
    {
        $this->authLoginLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $this->sessionRefreshLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $this->authRegisterLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $this->apiWriteLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $this->attachmentUploadLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $this->messageSendLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $this->passwordResetLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $this->searchLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $this->reportLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $this->security = $this->createMock(Security::class);
    }

    public function testDisabledListenerDoesNothing(): void
    {
        $this->authLoginLimiter->expects(self::never())->method('create');
        $this->apiWriteLimiter->expects(self::never())->method('create');

        $listener = $this->makeListener(enabled: false);
        $event = $this->makeEvent(Request::create('/auth', 'POST'));

        $listener->onPreAuthRequest($event);
        $listener->onWriteRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testGetRequestIsNotRateLimited(): void
    {
        $this->apiWriteLimiter->expects(self::never())->method('create');

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/api/v1/communities', 'GET'));
        $event->getRequest()->attributes->set('_route', '_api_/communities{._format}_get_collection');

        $listener->onWriteRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testExemptRouteIsNotRateLimited(): void
    {
        $this->apiWriteLimiter->expects(self::never())->method('create');

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/api/livekit/webhook', 'POST'));
        $event->getRequest()->attributes->set('_route', 'api_voice_webhook');

        $listener->onWriteRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testLoginRouteIsRateLimitedWhenExceeded(): void
    {
        $this->stubLimiter($this->authLoginLimiter, accepted: false);

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/auth', 'POST'));
        $event->getRequest()->attributes->set('_route', 'auth');

        $listener->onPreAuthRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $event->getResponse()->getStatusCode());
        self::assertSame('{"error":"Too many requests. Please try again later."}', $event->getResponse()->getContent());
    }

    public function testLoginRoutePassesWhenWithinLimit(): void
    {
        $this->stubLimiter($this->authLoginLimiter, accepted: true);

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/auth', 'POST'));
        $event->getRequest()->attributes->set('_route', 'auth');

        $listener->onPreAuthRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testLoginRouteIsNotDoubleLimitedInWritePhase(): void
    {
        $this->authLoginLimiter->expects(self::never())->method('create');
        $this->apiWriteLimiter->expects(self::never())->method('create');

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/auth', 'POST'));
        $event->getRequest()->attributes->set('_route', 'auth');

        $listener->onWriteRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testRegistrationRouteIsRateLimitedWhenExceeded(): void
    {
        $this->stubLimiter($this->authRegisterLimiter, accepted: false);

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/api/v1/users', 'POST'));
        $event->getRequest()->attributes->set('_route', '_api_/users{._format}_post');

        $listener->onPreAuthRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $event->getResponse()->getStatusCode());
    }

    public function testRegistrationRouteIsNotDoubleLimitedInWritePhase(): void
    {
        $this->authRegisterLimiter->expects(self::never())->method('create');
        $this->apiWriteLimiter->expects(self::never())->method('create');

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/api/v1/users', 'POST'));
        $event->getRequest()->attributes->set('_route', '_api_/users{._format}_post');

        $listener->onWriteRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testUsersMeWritesAreNotRegisterLimited(): void
    {
        $this->authRegisterLimiter->expects(self::never())->method('create');

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/api/v1/users/me/2fa/setup', 'POST'));
        $event->getRequest()->attributes->set('_route', '_api_/users/me/2fa/setup_post');

        $listener->onPreAuthRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testUsersMeWritesUseApiWriteLimiter(): void
    {
        $this->stubLimiter($this->apiWriteLimiter, accepted: false);

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/api/v1/users/me/change-password', 'POST'));
        $event->getRequest()->attributes->set('_route', '_api_/users/me/change-password_post');

        $listener->onWriteRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $event->getResponse()->getStatusCode());
    }

    public function testAttachmentUploadRouteIsRateLimitedWhenExceeded(): void
    {
        $this->stubLimiter($this->attachmentUploadLimiter, accepted: false);

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/api/v1/communities/test/channels/general/attachments', 'POST'));
        $event->getRequest()->attributes->set('_route', '_api_/communities/{community}/channels/{channel}/attachments_post');

        $listener->onWriteRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $event->getResponse()->getStatusCode());
    }

    public function testAttachmentUploadRoutePassesWhenWithinLimit(): void
    {
        $this->stubLimiter($this->attachmentUploadLimiter, accepted: true);

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/api/v1/communities/test/channels/general/attachments', 'POST'));
        $event->getRequest()->attributes->set('_route', '_api_/communities/{community}/channels/{channel}/attachments_post');

        $listener->onWriteRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testChannelMessagePostUsesMessageSendLimiter(): void
    {
        $this->apiWriteLimiter->expects(self::never())->method('create');
        $this->stubLimiter($this->messageSendLimiter, accepted: false);

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/api/v1/communities/test/channels/general/messages', 'POST'));
        $event->getRequest()->attributes->set('_route', '_api_/communities/{community}/channels/{channel}/messages_post');

        $listener->onWriteRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $event->getResponse()->getStatusCode());
    }

    public function testConversationMessagePostUsesMessageSendLimiter(): void
    {
        $this->apiWriteLimiter->expects(self::never())->method('create');
        $this->stubLimiter($this->messageSendLimiter, accepted: true);

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/api/v1/conversations/abc/messages', 'POST'));
        $event->getRequest()->attributes->set('_route', '_api_/conversations/{conversation}/messages_post');

        $listener->onWriteRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testThreadReplyPostUsesMessageSendLimiter(): void
    {
        $this->apiWriteLimiter->expects(self::never())->method('create');
        $this->stubLimiter($this->messageSendLimiter, accepted: false);

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/api/v1/messages/some-uuid/replies', 'POST'));
        $event->getRequest()->attributes->set('_route', '_api_/messages/{id}/replies_post');

        $listener->onWriteRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $event->getResponse()->getStatusCode());
    }

    public function testMessageSendIsKeyedOnAuthenticatedUser(): void
    {
        $user = new User();
        (new \ReflectionProperty($user, 'id'))->setValue($user, 42);
        $this->security->method('getUser')->willReturn($user);

        $this->stubLimiter($this->messageSendLimiter, accepted: true, expectedKey: 'message-uid-42');

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/api/v1/communities/test/channels/general/messages', 'POST'));
        $event->getRequest()->attributes->set('_route', '_api_/communities/{community}/channels/{channel}/messages_post');

        $listener->onWriteRequest($event);
    }

    public function testPasswordResetUsesDedicatedLimiterKeyedByIpAndEmail(): void
    {
        $this->apiWriteLimiter->expects(self::never())->method('create');
        $this->stubLimiter(
            $this->passwordResetLimiter,
            accepted: false,
            expectedKey: 'reset-127.0.0.1-'.sha1('victim@example.com'),
        );

        $listener = $this->makeListener();
        $request = Request::create(
            '/api/v1/reset_password',
            'POST',
            server: ['REMOTE_ADDR' => '127.0.0.1'],
            content: json_encode(['email' => 'Victim@Example.com'], \JSON_THROW_ON_ERROR),
        );
        $request->attributes->set('_route', '_api_/reset_password_post');
        $event = $this->makeEvent($request);

        $listener->onPreAuthRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $event->getResponse()->getStatusCode());
    }

    public function testPasswordResetSkippedByApiWriteLimiter(): void
    {
        $this->apiWriteLimiter->expects(self::never())->method('create');

        $listener = $this->makeListener();
        $request = Request::create('/api/v1/reset_password', 'POST', content: '{"email":"x@y.z"}');
        $request->attributes->set('_route', '_api_/reset_password_post');
        $event = $this->makeEvent($request);

        $listener->onWriteRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testApiWriteRouteIsRateLimitedWhenExceeded(): void
    {
        $this->stubLimiter($this->apiWriteLimiter, accepted: false);

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/api/v1/channels', 'POST'));
        $event->getRequest()->attributes->set('_route', '_api_/channels_post');

        $listener->onWriteRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $event->getResponse()->getStatusCode());
    }

    public function testApiWriteIsKeyedOnAuthenticatedUser(): void
    {
        $user = new User();
        (new \ReflectionProperty($user, 'id'))->setValue($user, 42);
        $this->security->method('getUser')->willReturn($user);

        $this->stubLimiter($this->apiWriteLimiter, accepted: true, expectedKey: 'write-uid-42');

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/api/v1/channels', 'POST'));
        $event->getRequest()->attributes->set('_route', '_api_/channels_post');

        $listener->onWriteRequest($event);
    }

    public function testApiWriteFallsBackToIpWhenUnauthenticated(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->stubLimiter($this->apiWriteLimiter, accepted: true, expectedKey: 'write-ip-127.0.0.1');

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/api/v1/reset-password-requests', 'POST'));
        $event->getRequest()->attributes->set('_route', '_api_/reset_password_requests_post');

        $listener->onWriteRequest($event);
    }

    public function testSearchRouteIsRateLimitedWhenExceeded(): void
    {
        $this->stubLimiter($this->searchLimiter, accepted: false);

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/api/v1/communities/acme/search', 'GET'));
        $event->getRequest()->attributes->set('_route', '_api_/communities/{identifier}/search_get');

        $listener->onReadRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $event->getResponse()->getStatusCode());
    }

    public function testSearchRouteIsKeyedOnAuthenticatedUser(): void
    {
        $user = new User();
        (new \ReflectionProperty($user, 'id'))->setValue($user, 7);
        $this->security->method('getUser')->willReturn($user);

        $this->stubLimiter($this->searchLimiter, accepted: true, expectedKey: 'search-uid-7');

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/api/v1/conversations/abc/search', 'GET'));
        $event->getRequest()->attributes->set('_route', '_api_/conversations/{identifier}/search_get');

        $listener->onReadRequest($event);
    }

    public function testNonSearchGetIsNotRateLimitedInReadPhase(): void
    {
        $this->searchLimiter->expects(self::never())->method('create');

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/api/v1/communities', 'GET'));
        $event->getRequest()->attributes->set('_route', '_api_/communities{._format}_get_collection');

        $listener->onReadRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testRateLimitedResponseIncludesRequiredHeaders(): void
    {
        $this->stubLimiter($this->authLoginLimiter, accepted: false);

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/auth', 'POST'));
        $event->getRequest()->attributes->set('_route', 'auth');

        $listener->onPreAuthRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertTrue($response->headers->has('Retry-After'));
        self::assertTrue($response->headers->has('X-RateLimit-Limit'));
        self::assertTrue($response->headers->has('X-RateLimit-Remaining'));
    }

    public function testTokenRefreshUsesItsOwnBudgetNotTheLoginOne(): void
    {
        // Sharing the login bucket logged users out after a handful of reloads.
        $this->authLoginLimiter->expects(self::never())->method('create');
        $this->stubLimiter($this->sessionRefreshLimiter, accepted: true);

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/token/refresh', 'POST'));
        $event->getRequest()->attributes->set('_route', 'token_refresh');

        $listener->onPreAuthRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testLogoutUsesTheSessionRefreshBudget(): void
    {
        $this->authLoginLimiter->expects(self::never())->method('create');
        $this->stubLimiter($this->sessionRefreshLimiter, accepted: true);

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/logout', 'POST'));
        $event->getRequest()->attributes->set('_route', 'logout');

        $listener->onPreAuthRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testTokenRefreshStillRejectsWhenItsOwnBudgetIsExhausted(): void
    {
        $this->stubLimiter($this->sessionRefreshLimiter, accepted: false);

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/token/refresh', 'POST'));
        $event->getRequest()->attributes->set('_route', 'token_refresh');

        $listener->onPreAuthRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $event->getResponse()->getStatusCode());
    }

    public function testTokenRefreshIsNotDoubleLimitedInWritePhase(): void
    {
        $this->apiWriteLimiter->expects(self::never())->method('create');

        $listener = $this->makeListener();
        $event = $this->makeEvent(Request::create('/token/refresh', 'POST'));
        $event->getRequest()->attributes->set('_route', 'token_refresh');

        $listener->onWriteRequest($event);

        self::assertFalse($event->hasResponse());
    }

    private function makeListener(bool $enabled = true): RateLimitListener
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Too many requests. Please try again later.');

        return new RateLimitListener(
            authLoginLimiter: $this->authLoginLimiter,
            sessionRefreshLimiter: $this->sessionRefreshLimiter,
            authRegisterLimiter: $this->authRegisterLimiter,
            apiWriteLimiter: $this->apiWriteLimiter,
            attachmentUploadLimiter: $this->attachmentUploadLimiter,
            messageSendLimiter: $this->messageSendLimiter,
            passwordResetLimiter: $this->passwordResetLimiter,
            searchLimiter: $this->searchLimiter,
            reportLimiter: $this->reportLimiter,
            security: $this->security,
            rateLimitResponses: new RateLimitResponseFactory($translator),
            enabled: $enabled,
        );
    }

    private function makeEvent(Request $request): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function stubLimiter(RateLimiterFactoryInterface&MockObject $factory, bool $accepted, ?string $expectedKey = null): void
    {
        $rateLimit = new RateLimit(
            availableTokens: $accepted ? 1 : 0,
            retryAfter: new \DateTimeImmutable('+60 seconds'),
            accepted: $accepted,
            limit: 5,
        );

        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->method('consume')->willReturn($rateLimit);

        if (null !== $expectedKey) {
            $factory->expects(self::once())->method('create')->with($expectedKey)->willReturn($limiter);
        } else {
            $factory->method('create')->willReturn($limiter);
        }
    }
}
