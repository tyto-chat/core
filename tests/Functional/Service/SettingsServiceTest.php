<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Dto\Admin\ServerConfigPatchDto;
use App\Enum\Settings\SupportedLocale;
use App\Service\Settings\SettingsService;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class SettingsServiceTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function service(): SettingsServiceInterface
    {
        $s = static::getContainer()->get(SettingsServiceInterface::class);
        \assert($s instanceof SettingsServiceInterface);

        return $s;
    }

    public function testGetReturnsRegistryDefaultWhenNoRow(): void
    {
        self::assertSame(5, $this->service()->get(Settings::rateLoginLimit()));
        self::assertSame(SupportedLocale::English, $this->service()->get(Settings::defaultLocale()));
        self::assertNull($this->service()->get(Settings::smtpHost()));
        self::assertFalse($this->service()->has(Settings::smtpPassword()));
        self::assertFalse($this->service()->isSmtpConfigured());
    }

    public function testApplyPatchPersistsOverridesAndReadsBack(): void
    {
        $dto = new ServerConfigPatchDto();
        $dto->rateLoginLimit = 99;
        $dto->registrationEnabled = false;
        $dto->defaultLocale = SupportedLocale::Polish;
        $dto->smtpHost = 'smtp.example.com';

        $this->service()->applyPatch($dto);

        $fresh = static::getContainer()->get(SettingsServiceInterface::class);
        \assert($fresh instanceof SettingsServiceInterface);
        self::assertSame(99, $fresh->get(Settings::rateLoginLimit()));
        self::assertFalse($fresh->get(Settings::registrationEnabled()));
        self::assertSame(SupportedLocale::Polish, $fresh->get(Settings::defaultLocale()));
        self::assertSame('smtp.example.com', $fresh->get(Settings::smtpHost()));
        self::assertTrue($fresh->isSmtpConfigured());
        self::assertSame(60, $fresh->get(Settings::rateLoginIntervalSeconds()));
    }

    public function testSecretIsEncryptedAndFlagged(): void
    {
        $dto = new ServerConfigPatchDto();
        $dto->smtpPassword = 'hunter2';
        $this->service()->applyPatch($dto);

        self::assertTrue($this->service()->has(Settings::smtpPassword()));
        self::assertNotSame('hunter2', $this->service()->get(Settings::smtpPassword()));

        $keep = new ServerConfigPatchDto();
        $keep->smtpPassword = '';
        $this->service()->applyPatch($keep);
        self::assertTrue($this->service()->has(Settings::smtpPassword()));
    }

    public function testNormalizationOnApplyPatch(): void
    {
        $dto = new ServerConfigPatchDto();
        $dto->accentColor = '#FF00AA';
        $dto->serverName = '  Spaced  ';
        $dto->smtpHost = '';
        $this->service()->applyPatch($dto);

        $fresh = static::getContainer()->get(SettingsServiceInterface::class);
        \assert($fresh instanceof SettingsServiceInterface);
        self::assertSame('#ff00aa', $fresh->get(Settings::accentColor()));
        self::assertSame('Spaced', $fresh->get(Settings::serverName()));
        self::assertNull($fresh->get(Settings::smtpHost())); // '' normalized to null
    }

    public function testCacheInvalidatesOnWriteAndReset(): void
    {
        $s = static::getContainer()->get(SettingsServiceInterface::class);
        \assert($s instanceof SettingsService);
        // Prime the cache with a read.
        self::assertSame(5, $s->get(Settings::rateLoginLimit()));

        // Same instance: applyPatch must invalidate so the next read sees the change.
        $dto = new ServerConfigPatchDto();
        $dto->rateLoginLimit = 42;
        $s->applyPatch($dto);
        self::assertSame(42, $s->get(Settings::rateLoginLimit()));

        // reset() drops the cache; value still reads from DB afterwards.
        $s->reset();
        self::assertSame(42, $s->get(Settings::rateLoginLimit()));
    }

    public function testLastUpdateTracksMostRecent(): void
    {
        self::assertNull($this->service()->lastUpdate()['at']);
        $dto = new ServerConfigPatchDto();
        $dto->serverName = 'Renamed';
        $this->service()->applyPatch($dto);
        self::assertInstanceOf(\DateTimeImmutable::class, $this->service()->lastUpdate()['at']);
    }
}
