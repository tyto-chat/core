<?php

declare(strict_types=1);

namespace App\Tests\Integration\Moderation;

use App\Entity\Community;
use App\Entity\ModerationAction;
use App\Entity\User;
use App\EventListener\AutoTimeoutListener;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Moderation\AutoModerationServiceInterface;
use App\Service\Moderation\ModerationServiceInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Service\User\BotUserServiceInterface;
use App\Service\UserContextServiceInterface;
use App\Settings\SettingDef;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[AllowMockObjectsWithoutExpectations]
class AutoTimeoutListenerTest extends TestCase
{
    private const USER_ID = 992001;
    private const SLUG = 'itest-auto-timeout';
    private const COUNTER_KEY = 'auto_timeout:'.self::SLUG.':'.self::USER_ID;

    private const HITS = 3;
    private const WINDOW_SECONDS = 120;

    private Client $redis;
    private AutoTimeoutListener $listener;

    private User $user;
    private int $timeoutsApplied = 0;

    /** @var array<string, mixed> */
    private array $settings = [];

    #[\Override]
    protected function setUp(): void
    {
        $dsn = $_ENV['REDIS_DSN'] ?? $_SERVER['REDIS_DSN'] ?? null;
        self::assertIsString($dsn, 'REDIS_DSN must be set for integration tests.');

        $this->redis = new Client($dsn, ['exceptions' => true]);
        $this->redis->del([self::COUNTER_KEY]);
        $this->timeoutsApplied = 0;

        $this->settings = [
            'autoTimeoutEnabled' => true,
            'autoTimeoutHits' => self::HITS,
            'autoTimeoutWindowSeconds' => self::WINDOW_SECONDS,
        ];

        $this->user = $this->buildUser(isBot: false, isAdmin: false);

        $settingsService = $this->createMock(SettingsServiceInterface::class);
        $settingsService->method('get')->willReturnCallback(
            fn (SettingDef $def): mixed => $this->settings[$def->key] ?? $def->default,
        );

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturnCallback(fn (): User => $this->user);

        $bot = $this->createMock(User::class);
        $botService = $this->createMock(BotUserServiceInterface::class);
        $botService->method('getAutoModeratorBot')->willReturn($bot);

        $community = $this->createMock(Community::class);
        $communityService = $this->createMock(CommunityServiceInterface::class);
        $communityService->method('findByIdentifier')->willReturn($community);

        $autoModeration = $this->createMock(AutoModerationServiceInterface::class);
        $autoModeration->method('computeAutoTimeoutDuration')
            ->willReturn(new \DateTimeImmutable('+10 minutes'));

        $moderation = $this->createMock(ModerationServiceInterface::class);
        $moderation->method('timeout')->willReturnCallback(function (): ModerationAction {
            ++$this->timeoutsApplied;

            return $this->createMock(ModerationAction::class);
        });

        $userContext = $this->createMock(UserContextServiceInterface::class);
        $userContext->method('runAs')->willReturnCallback(fn (User $actor, callable $fn): mixed => $fn());

        $this->listener = new AutoTimeoutListener(
            $autoModeration,
            $moderation,
            $userContext,
            $botService,
            $communityService,
            $security,
            $this->redis,
            new NullLogger(),
            $settingsService,
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->redis->del([self::COUNTER_KEY]);
    }

    private function buildUser(bool $isBot, bool $isAdmin): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(self::USER_ID);
        $user->method('isBot')->willReturn($isBot);
        $user->method('hasRole')->willReturn($isAdmin);

        return $user;
    }

    private function dispatch(
        int $status = Response::HTTP_TOO_MANY_REQUESTS,
        string $path = '/api/v1/communities/'.self::SLUG.'/channels/general/messages',
    ): void {
        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create($path, 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            new Response('', $status),
        );

        ($this->listener)($event);
    }

    public function testEachRateLimitedResponseIncrementsTheWindowCounter(): void
    {
        $this->dispatch();
        $this->dispatch();

        self::assertSame('2', $this->redis->get(self::COUNTER_KEY));
        self::assertSame(0, $this->timeoutsApplied);
    }

    public function testCounterWindowIsPinnedToTheFirstHit(): void
    {
        $this->dispatch();
        $firstTtl = (int) $this->redis->ttl(self::COUNTER_KEY);
        self::assertGreaterThan(0, $firstTtl);
        self::assertLessThanOrEqual(self::WINDOW_SECONDS, $firstTtl);

        $this->redis->expire(self::COUNTER_KEY, 5);
        $this->dispatch();

        self::assertLessThanOrEqual(
            5,
            (int) $this->redis->ttl(self::COUNTER_KEY),
            'expire NX must not extend the window on later hits.',
        );
    }

    public function testReachingTheThresholdAppliesTimeoutAndResetsTheCounter(): void
    {
        $this->dispatch();
        $this->dispatch();
        $this->dispatch();

        self::assertSame(1, $this->timeoutsApplied);
        self::assertNull($this->redis->get(self::COUNTER_KEY), 'Counter is deleted after a successful timeout.');
    }

    public function testNon429ResponsesDoNotCount(): void
    {
        $this->dispatch(Response::HTTP_OK);

        self::assertNull($this->redis->get(self::COUNTER_KEY));
    }

    public function testNonCommunityPathsDoNotCount(): void
    {
        $this->dispatch(path: '/api/v1/conversations/x/messages');

        self::assertNull($this->redis->get(self::COUNTER_KEY));
    }

    public function testDisabledSettingShortCircuits(): void
    {
        $this->settings['autoTimeoutEnabled'] = false;

        $this->dispatch();

        self::assertNull($this->redis->get(self::COUNTER_KEY));
    }

    public function testAdminStillCountsButIsNeverTimedOut(): void
    {
        $this->user = $this->buildUser(isBot: false, isAdmin: true);

        $this->dispatch();
        $this->dispatch();
        $this->dispatch();

        self::assertSame(0, $this->timeoutsApplied);
        self::assertSame('3', $this->redis->get(self::COUNTER_KEY));
    }

    public function testFailedTimeoutKeepsTheCounterForRetry(): void
    {
        $moderation = $this->createMock(ModerationServiceInterface::class);
        $moderation->method('timeout')->willThrowException(new \RuntimeException('db down'));

        $listener = new AutoTimeoutListener(
            $this->autoModerationMock(),
            $moderation,
            $this->passthroughUserContext(),
            $this->botServiceMock(),
            $this->communityServiceMock(),
            $this->securityMock(),
            $this->redis,
            new NullLogger(),
            $this->settingsMock(),
        );

        for ($i = 0; $i < self::HITS; ++$i) {
            $event = new ResponseEvent(
                $this->createMock(HttpKernelInterface::class),
                Request::create('/api/v1/communities/'.self::SLUG.'/channels/general/messages', 'POST'),
                HttpKernelInterface::MAIN_REQUEST,
                new Response('', Response::HTTP_TOO_MANY_REQUESTS),
            );
            $listener($event);
        }

        self::assertSame((string) self::HITS, $this->redis->get(self::COUNTER_KEY));
    }

    private function autoModerationMock(): AutoModerationServiceInterface
    {
        $mock = $this->createMock(AutoModerationServiceInterface::class);
        $mock->method('computeAutoTimeoutDuration')->willReturn(new \DateTimeImmutable('+10 minutes'));

        return $mock;
    }

    private function passthroughUserContext(): UserContextServiceInterface
    {
        $mock = $this->createMock(UserContextServiceInterface::class);
        $mock->method('runAs')->willReturnCallback(fn (User $actor, callable $fn): mixed => $fn());

        return $mock;
    }

    private function botServiceMock(): BotUserServiceInterface
    {
        $mock = $this->createMock(BotUserServiceInterface::class);
        $mock->method('getAutoModeratorBot')->willReturn($this->createMock(User::class));

        return $mock;
    }

    private function communityServiceMock(): CommunityServiceInterface
    {
        $mock = $this->createMock(CommunityServiceInterface::class);
        $mock->method('findByIdentifier')->willReturn($this->createMock(Community::class));

        return $mock;
    }

    private function securityMock(): Security
    {
        $mock = $this->createMock(Security::class);
        $mock->method('getUser')->willReturnCallback(fn (): User => $this->user);

        return $mock;
    }

    private function settingsMock(): SettingsServiceInterface
    {
        $mock = $this->createMock(SettingsServiceInterface::class);
        $mock->method('get')->willReturnCallback(
            fn (SettingDef $def): mixed => $this->settings[$def->key] ?? $def->default,
        );

        return $mock;
    }
}
