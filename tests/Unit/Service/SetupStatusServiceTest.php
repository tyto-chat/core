<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\Admin\ConfigStatusServiceInterface;
use App\Service\Admin\SetupStatusService;
use PHPUnit\Framework\TestCase;

final class SetupStatusServiceTest extends TestCase
{
    private function build(bool $smtp, bool $bot, bool $mercure, bool $meili): SetupStatusService
    {
        $cfg = self::createStub(ConfigStatusServiceInterface::class);
        $cfg->method('isSmtpConfigured')->willReturn($smtp);
        $cfg->method('isDefaultBotConfigured')->willReturn($bot);
        $cfg->method('isMercureConfigured')->willReturn($mercure);
        $cfg->method('isMeiliConfigured')->willReturn($meili);

        return new SetupStatusService($cfg);
    }

    public function testAllSatisfiedNeedsNoAttention(): void
    {
        $status = $this->build(true, true, true, true)->status();
        self::assertFalse($status->needsAttention);
        self::assertCount(4, $status->items);
        foreach ($status->items as $item) {
            self::assertTrue($item->satisfied);
        }
    }

    public function testAnyUnsatisfiedNeedsAttention(): void
    {
        $status = $this->build(false, true, true, true)->status();
        self::assertTrue($status->needsAttention);
        $smtp = array_values(array_filter($status->items, static fn ($i) => 'smtp' === $i->key))[0];
        self::assertFalse($smtp->satisfied);
        self::assertSame('ui', $smtp->fixable);
        self::assertSame('email', $smtp->deepLink);
    }

    public function testInfraItemsHaveNoDeepLink(): void
    {
        $status = $this->build(true, true, false, false)->status();
        $mercure = array_values(array_filter($status->items, static fn ($i) => 'mercure' === $i->key))[0];
        self::assertSame('infra', $mercure->fixable);
        self::assertNull($mercure->deepLink);
    }
}
