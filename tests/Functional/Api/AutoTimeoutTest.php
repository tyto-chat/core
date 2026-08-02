<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\ModerationAction;
use App\Entity\User;
use App\Enum\Moderation\ModerationActionType;
use App\EventListener\AutoTimeoutListener;
use App\Exception\User\UserNotFoundException;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Predis\ClientInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[AllowMockObjectsWithoutExpectations]
class AutoTimeoutTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function buildSettingsMock(bool $enabled = true, int $hits = 3, int $window = 300): SettingsServiceInterface
    {
        $settingsMock = $this->createMock(SettingsServiceInterface::class);
        $settingsMock->method('get')->willReturnCallback(static function (mixed $def) use ($enabled, $hits, $window): mixed {
            return match ($def->key) {
                Settings::autoTimeoutEnabled()->key => $enabled,
                Settings::autoTimeoutHits()->key => $hits,
                Settings::autoTimeoutWindowSeconds()->key => $window,
                default => throw new \LogicException('Unexpected setting key: '.$def->key),
            };
        });

        return $settingsMock;
    }

    private function buildRedisFake(): ClientInterface
    {
        /** @var \ArrayObject<string, int> $store */
        $store = new \ArrayObject();
        $mock = $this->createMock(ClientInterface::class);
        $mock->method('__call')->willReturnCallback(static function (string $cmd, array $args) use ($store): mixed {
            $key = (string) ($args[0] ?? '');

            switch ($cmd) {
                case 'incr':
                    $store[$key] = (int) ($store[$key] ?? 0) + 1;

                    return $store[$key];
                case 'del':
                    unset($store[$key]);

                    return 1;
                default:
                    return 1;
            }
        });

        return $mock;
    }

    private function buildListener(User $authenticatedUser, int $hits = 3): AutoTimeoutListener
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($authenticatedUser);

        return new AutoTimeoutListener(
            static::getContainer()->get('App\Service\Moderation\AutoModerationServiceInterface'),
            static::getContainer()->get('App\Service\Moderation\ModerationServiceInterface'),
            static::getContainer()->get('App\Service\UserContextServiceInterface'),
            static::getContainer()->get('App\Service\User\BotUserServiceInterface'),
            static::getContainer()->get('App\Service\Community\CommunityServiceInterface'),
            $security,
            $this->buildRedisFake(),
            new NullLogger(),
            $this->buildSettingsMock(hits: $hits),
        );
    }

    private function fire429Event(AutoTimeoutListener $listener, string $communitySlug): void
    {
        $kernel = static::getContainer()->get(HttpKernelInterface::class);
        $request = Request::create('/api/v1/communities/'.$communitySlug.'/channels/general/messages', 'POST');
        $response = new Response('', 429);
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $listener($event);
    }

    public function testAutoTimeoutCreatedAfterThresholdHits(): void
    {
        $user = UserFactory::createOne();
        $botUser = UserFactory::new()->bot()->with(['email' => 'bot@tyto.test'])->create();
        CommunityFactory::new()->withIdentifier('auto-timeout-test')->create();

        $botService = $this->createMock(\App\Service\User\BotUserServiceInterface::class);
        $botService->method('getAutoModeratorBot')->willReturn($botUser);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $listener = new AutoTimeoutListener(
            static::getContainer()->get('App\Service\Moderation\AutoModerationServiceInterface'),
            static::getContainer()->get('App\Service\Moderation\ModerationServiceInterface'),
            static::getContainer()->get('App\Service\UserContextServiceInterface'),
            $botService,
            static::getContainer()->get('App\Service\Community\CommunityServiceInterface'),
            $security,
            $this->buildRedisFake(),
            new NullLogger(),
            $this->buildSettingsMock(hits: 3),
        );

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $repo = $em->getRepository(ModerationAction::class);

        // Two 429s — no timeout yet
        $this->fire429Event($listener, 'auto-timeout-test');
        $this->fire429Event($listener, 'auto-timeout-test');
        self::assertCount(0, $repo->findAll());

        // Third 429 — threshold reached
        $this->fire429Event($listener, 'auto-timeout-test');

        $actions = $repo->findAll();
        self::assertCount(1, $actions);
        self::assertSame(ModerationActionType::Timeout, $actions[0]->getType());
        self::assertSame($user->getId(), $actions[0]->getTargetUser()->getId());
        self::assertSame($botUser->getId(), $actions[0]->getActorUser()->getId());
    }

    public function testAutoTimeoutSkipsAdminUsers(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $botUser = UserFactory::new()->bot()->with(['email' => 'bot2@tyto.test'])->create();
        CommunityFactory::new()->withIdentifier('auto-timeout-admin')->create();

        $botService = $this->createMock(\App\Service\User\BotUserServiceInterface::class);
        $botService->method('getDefault')->willReturn($botUser);
        static::getContainer()->set('App\Service\User\BotUserServiceInterface', $botService);

        $listener = $this->buildListener($admin, hits: 2);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $repo = $em->getRepository(ModerationAction::class);

        $this->fire429Event($listener, 'auto-timeout-admin');
        $this->fire429Event($listener, 'auto-timeout-admin');
        $this->fire429Event($listener, 'auto-timeout-admin');

        self::assertCount(0, $repo->findAll());
    }

    public function testAutoTimeoutSkippedWhenBotNotConfigured(): void
    {
        $user = UserFactory::createOne();
        CommunityFactory::new()->withIdentifier('auto-timeout-nobot')->create();

        // Bot service throws RuntimeException (bot ID = 0)
        $botService = $this->createMock(\App\Service\User\BotUserServiceInterface::class);
        $botService->method('getAutoModeratorBot')->willThrowException(new \RuntimeException('Not configured.'));
        static::getContainer()->set('App\Service\User\BotUserServiceInterface', $botService);

        $listener = $this->buildListener($user, hits: 2);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $repo = $em->getRepository(ModerationAction::class);

        $this->fire429Event($listener, 'auto-timeout-nobot');
        $this->fire429Event($listener, 'auto-timeout-nobot');

        self::assertCount(0, $repo->findAll());
    }

    public function testAutoTimeoutSkippedWhenBotUserNotFound(): void
    {
        $user = UserFactory::createOne();
        CommunityFactory::new()->withIdentifier('auto-timeout-usernotfound')->create();

        // Bot service throws UserNotFoundException (stale/non-bot autoModeratorBotId)
        $botService = $this->createMock(\App\Service\User\BotUserServiceInterface::class);
        $botService->method('getAutoModeratorBot')->willThrowException(new UserNotFoundException('bot gone'));
        static::getContainer()->set('App\Service\User\BotUserServiceInterface', $botService);

        $listener = $this->buildListener($user, hits: 2);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $repo = $em->getRepository(ModerationAction::class);

        $this->fire429Event($listener, 'auto-timeout-usernotfound');
        $this->fire429Event($listener, 'auto-timeout-usernotfound');

        self::assertCount(0, $repo->findAll());
    }

    public function testListenerIgnoresNon429Responses(): void
    {
        $user = UserFactory::createOne();
        CommunityFactory::new()->withIdentifier('auto-timeout-200')->create();

        $listener = $this->buildListener($user, hits: 1);

        $kernel = static::getContainer()->get(HttpKernelInterface::class);
        $request = Request::create('/api/v1/communities/auto-timeout-200/channels/general/messages', 'POST');
        $response = new Response('', 200);
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $listener($event);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $repo = $em->getRepository(ModerationAction::class);
        self::assertCount(0, $repo->findAll());
    }
}
