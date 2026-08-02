<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Exception\User\UserNotFoundException;
use App\Service\Admin\ConfigStatusService;
use App\Service\Settings\SettingsServiceInterface;
use App\Service\User\BotUserServiceInterface;
use PHPUnit\Framework\TestCase;

final class ConfigStatusServiceTest extends TestCase
{
    private function build(
        bool $smtp,
        ?User $defaultBot,
        ?\Throwable $botThrows,
        string $mercureUrl,
        string $meiliKey,
    ): ConfigStatusService {
        $settings = self::createStub(SettingsServiceInterface::class);
        $settings->method('isSmtpConfigured')->willReturn($smtp);

        $bots = self::createStub(BotUserServiceInterface::class);
        if (null !== $botThrows) {
            $bots->method('getDefault')->willThrowException($botThrows);
        } else {
            $bots->method('getDefault')->willReturn($defaultBot ?? self::createStub(User::class));
        }

        return new ConfigStatusService($settings, $bots, $mercureUrl, $meiliKey);
    }

    public function testSmtpReflectsSettings(): void
    {
        self::assertTrue($this->build(true, null, null, 'https://m.example.org/.well-known/mercure', 'k')->isSmtpConfigured());
        self::assertFalse($this->build(false, null, null, 'x', 'k')->isSmtpConfigured());
    }

    public function testDefaultBotConfiguredWhenResolves(): void
    {
        self::assertTrue($this->build(true, self::createStub(User::class), null, 'x', 'k')->isDefaultBotConfigured());
    }

    public function testDefaultBotNotConfiguredWhenUnset(): void
    {
        self::assertFalse($this->build(true, null, new \RuntimeException('unset'), 'x', 'k')->isDefaultBotConfigured());
    }

    public function testDefaultBotNotConfiguredWhenStaleId(): void
    {
        self::assertFalse($this->build(true, null, new UserNotFoundException('gone'), 'x', 'k')->isDefaultBotConfigured());
    }

    public function testMercureConfiguredRejectsPlaceholder(): void
    {
        self::assertFalse($this->build(true, null, null, 'https://example.com/.well-known/mercure', 'k')->isMercureConfigured());
        self::assertFalse($this->build(true, null, null, '', 'k')->isMercureConfigured());
        self::assertTrue($this->build(true, null, null, 'https://mercure.myhost.org/.well-known/mercure', 'k')->isMercureConfigured());
    }

    public function testMeiliConfiguredRequiresNonEmptyKey(): void
    {
        self::assertFalse($this->build(true, null, null, 'x', '')->isMeiliConfigured());
        self::assertTrue($this->build(true, null, null, 'x', 'a-real-key')->isMeiliConfigured());
    }
}
