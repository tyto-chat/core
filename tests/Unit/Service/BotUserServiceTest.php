<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Exception\User\UserNotFoundException;
use App\Service\Settings\SettingsServiceInterface;
use App\Service\User\BotUserService;
use App\Service\User\UserServiceInterface;
use App\Settings\Settings;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class BotUserServiceTest extends TestCase
{
    private UserServiceInterface&MockObject $userService;

    private function buildService(int $defaultBotId, int $welcomeBotId = 0, int $autoModeratorBotId = 0): BotUserService
    {
        $this->userService = $this->createMock(UserServiceInterface::class);

        $settings = $this->createMock(SettingsServiceInterface::class);
        $settings->method('get')->willReturnCallback(static function (mixed $def) use ($defaultBotId, $welcomeBotId, $autoModeratorBotId): mixed {
            return match ($def->key) {
                Settings::defaultBotId()->key => $defaultBotId,
                Settings::welcomeBotId()->key => $welcomeBotId,
                Settings::autoModeratorBotId()->key => $autoModeratorBotId,
                default => throw new \UnexpectedValueException('Unexpected setting key: '.$def->key),
            };
        });

        return new BotUserService($this->userService, $settings);
    }

    public function testGetReturnsBotUser(): void
    {
        $service = $this->buildService(5);
        $bot = $this->createMock(User::class);
        $bot->method('isBot')->willReturn(true);
        $this->userService->method('find')->willReturn($bot);

        self::assertSame($bot, $service->get(5));
    }

    public function testGetThrowsWhenUserMissing(): void
    {
        $service = $this->buildService(5);
        $this->userService->method('find')->willReturn(null);

        $this->expectException(UserNotFoundException::class);
        $service->get(5);
    }

    public function testGetThrowsWhenUserNotBot(): void
    {
        $service = $this->buildService(5);
        $human = $this->createMock(User::class);
        $human->method('isBot')->willReturn(false);
        $this->userService->method('find')->willReturn($human);

        $this->expectException(UserNotFoundException::class);
        $service->get(5);
    }

    public function testGetDefaultThrowsWhenUnconfigured(): void
    {
        $service = $this->buildService(0);

        $this->expectException(\RuntimeException::class);
        $service->getDefault();
    }

    public function testGetDefaultResolvesConfiguredBot(): void
    {
        $service = $this->buildService(9);
        $bot = $this->createMock(User::class);
        $bot->method('isBot')->willReturn(true);
        $this->userService->method('find')->willReturn($bot);

        self::assertSame($bot, $service->getDefault());
    }

    public function testGetWelcomeBotUsesRoleBotWhenSet(): void
    {
        $service = $this->buildService(1, welcomeBotId: 5);
        $bot = $this->createMock(User::class);
        $bot->method('isBot')->willReturn(true);
        $this->userService->method('find')->willReturnCallback(
            static fn ($id) => 5 === $id ? $bot : null,
        );

        self::assertSame($bot, $service->getWelcomeBot());
    }

    public function testGetWelcomeBotFallsBackToDefault(): void
    {
        $service = $this->buildService(9, welcomeBotId: 0);
        $bot = $this->createMock(User::class);
        $bot->method('isBot')->willReturn(true);
        $this->userService->method('find')->willReturnCallback(
            static fn ($id) => 9 === $id ? $bot : null,
        );

        self::assertSame($bot, $service->getWelcomeBot());
    }

    public function testGetWelcomeBotThrowsWhenRoleAndDefaultUnset(): void
    {
        $service = $this->buildService(0, welcomeBotId: 0);

        $this->expectException(\RuntimeException::class);
        $service->getWelcomeBot();
    }

    public function testGetAutoModeratorBotUsesRoleBotWhenSet(): void
    {
        $service = $this->buildService(1, autoModeratorBotId: 6);
        $bot = $this->createMock(User::class);
        $bot->method('isBot')->willReturn(true);
        $this->userService->method('find')->willReturnCallback(
            static fn ($id) => 6 === $id ? $bot : null,
        );

        self::assertSame($bot, $service->getAutoModeratorBot());
    }

    public function testGetAutoModeratorBotFallsBackToDefault(): void
    {
        $service = $this->buildService(9, autoModeratorBotId: 0);
        $bot = $this->createMock(User::class);
        $bot->method('isBot')->willReturn(true);
        $this->userService->method('find')->willReturnCallback(
            static fn ($id) => 9 === $id ? $bot : null,
        );

        self::assertSame($bot, $service->getAutoModeratorBot());
    }

    public function testGetAutoModeratorBotThrowsWhenRoleAndDefaultUnset(): void
    {
        $service = $this->buildService(0, autoModeratorBotId: 0);

        $this->expectException(\RuntimeException::class);
        $service->getAutoModeratorBot();
    }
}
