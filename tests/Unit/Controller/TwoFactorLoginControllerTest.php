<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\TwoFactorLoginController;
use App\Entity\User;
use App\EventListener\TwoFactorAuthenticationSuccessListener;
use App\Http\RateLimitResponseFactory;
use App\Service\User\TwoFactorServiceInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Authentication\AuthenticationSuccessHandler;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class TwoFactorLoginControllerTest extends TestCase
{
    private Security&MockObject $security;
    private TwoFactorServiceInterface&MockObject $twoFactorService;
    private AuthenticationSuccessHandler&MockObject $successHandler;
    private RateLimiterFactoryInterface&MockObject $twoFactorLimiter;
    private TranslatorInterface&MockObject $translator;
    private TwoFactorLoginController $controller;

    #[\Override]
    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->twoFactorService = $this->createMock(TwoFactorServiceInterface::class);
        $this->successHandler = $this->createMock(AuthenticationSuccessHandler::class);
        $this->twoFactorLimiter = $this->createMock(RateLimiterFactoryInterface::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->translator->method('trans')->willReturn('Too many requests. Please try again later.');

        $this->controller = new TwoFactorLoginController(
            $this->security,
            $this->twoFactorService,
            $this->successHandler,
            $this->twoFactorLimiter,
            new RateLimitResponseFactory($this->translator),
        );
    }

    private function pendingUser(int $id): User
    {
        $user = new User();
        (new \ReflectionProperty($user, 'id'))->setValue($user, $id);

        $token = $this->createMock(TokenInterface::class);
        $token->method('hasAttribute')->willReturnCallback(
            fn (string $name): bool => TwoFactorAuthenticationSuccessListener::PENDING_CLAIM === $name,
        );

        $this->security->method('getUser')->willReturn($user);
        $this->security->method('getToken')->willReturn($token);

        return $user;
    }

    private function stubLimiter(bool $accepted): void
    {
        $rateLimit = new RateLimit(
            availableTokens: $accepted ? 1 : 0,
            retryAfter: new \DateTimeImmutable('+60 seconds'),
            accepted: $accepted,
            limit: 5,
        );

        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->method('consume')->willReturn($rateLimit);

        $this->twoFactorLimiter->method('create')->willReturn($limiter);
    }

    public function testRejectsWithoutConsumingVerifyLoginWhenLimitExceeded(): void
    {
        $this->pendingUser(42);
        $this->stubLimiter(accepted: false);

        $this->twoFactorService->expects(self::never())->method('verifyLogin');
        $this->successHandler->expects(self::never())->method('handleAuthenticationSuccess');

        $response = $this->controller->verify(Request::create('/auth/2fa', 'POST', content: '{"code":"123456"}'));

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertTrue($response->headers->has('Retry-After'));
        self::assertTrue($response->headers->has('X-RateLimit-Limit'));
        self::assertTrue($response->headers->has('X-RateLimit-Remaining'));
        self::assertSame('{"error":"Too many requests. Please try again later."}', $response->getContent());
    }

    public function testConsumesLimiterKeyedByUserIdBeforeVerifying(): void
    {
        $this->pendingUser(42);

        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->method('consume')->willReturn(new RateLimit(
            availableTokens: 1,
            retryAfter: new \DateTimeImmutable('+60 seconds'),
            accepted: true,
            limit: 5,
        ));
        $this->twoFactorLimiter->expects(self::once())->method('create')->with('2fa-42')->willReturn($limiter);

        $this->twoFactorService->expects(self::once())->method('verifyLogin');
        $this->successHandler->expects(self::once())->method('handleAuthenticationSuccess')
            ->willReturn(new Response('ok'));

        $response = $this->controller->verify(Request::create('/auth/2fa', 'POST', content: '{"code":"123456"}'));

        self::assertSame('ok', $response->getContent());
    }

    public function testAllowsVerificationWhenWithinLimit(): void
    {
        $this->pendingUser(7);
        $this->stubLimiter(accepted: true);

        $this->twoFactorService->expects(self::once())->method('verifyLogin');
        $this->successHandler->method('handleAuthenticationSuccess')->willReturn(new Response('ok'));

        $response = $this->controller->verify(Request::create('/auth/2fa', 'POST', content: '{"code":"123456"}'));

        self::assertSame('ok', $response->getContent());
    }
}
