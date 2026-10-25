<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\HttpCache;

use App\Service\HttpCache\HttpCacheSwitch;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class HttpCacheSwitchTest extends TestCase
{
    private string $dir;
    private string $flagFile;

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tyto-http-cache-'.bin2hex(random_bytes(4));
        $this->flagFile = $this->dir.'/http-cache.on';
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    private function presenceFlagFile(): string
    {
        return dirname($this->flagFile).'/http-cache-presence.on';
    }

    private function switchWith(int $pageTtl, int $presenceTtl): HttpCacheSwitch
    {
        $settings = self::createStub(SettingsServiceInterface::class);
        $settings->method('get')->willReturnCallback(
            static fn ($def): int => $def->key === Settings::httpCachePageTtlSeconds()->key ? $pageTtl : $presenceTtl,
        );

        return new HttpCacheSwitch($settings, new Filesystem(), $this->flagFile);
    }

    /** @return iterable<string, array{int, int, bool, bool}> */
    public static function ttlCombinations(): iterable
    {
        yield 'both off' => [0, 0, false, false];
        yield 'pages on' => [30, 0, true, false];
        yield 'presence on' => [0, 15, false, true];
        yield 'both on' => [30, 15, true, true];
    }

    #[DataProvider('ttlCombinations')]
    public function testSyncMirrorsEachTtlIntoItsOwnFlagFile(int $pageTtl, int $presenceTtl, bool $pagesOn, bool $presenceOn): void
    {
        $switch = $this->switchWith($pageTtl, $presenceTtl);

        self::assertSame($pagesOn || $presenceOn, $switch->sync());
        self::assertSame($pagesOn, $switch->isOn());
        self::assertSame($presenceOn, $switch->isPresenceOn());
        self::assertSame($pagesOn, is_file($this->flagFile));
        self::assertSame($presenceOn, is_file($this->presenceFlagFile()));
    }

    public function testSyncCreatesTheSharedSwitchDirectory(): void
    {
        self::assertDirectoryDoesNotExist($this->dir);

        $this->switchWith(30, 15)->sync();

        self::assertFileExists($this->flagFile);
        self::assertFileExists($this->presenceFlagFile());
    }

    public function testDisablingPagesWhilePresenceStaysOnRemovesOnlyThePagesFlag(): void
    {
        $this->switchWith(3600, 15)->sync();
        self::assertTrue(is_file($this->flagFile));

        $this->switchWith(0, 15)->sync();

        self::assertFileDoesNotExist($this->flagFile);
        self::assertFileExists($this->presenceFlagFile());
    }

    public function testTripTurnsBothCategoriesOff(): void
    {
        $switch = $this->switchWith(30, 15);
        $switch->sync();

        $switch->trip(600);

        self::assertFalse($switch->isOn());
        self::assertFalse($switch->isPresenceOn());
        self::assertFileDoesNotExist($this->presenceFlagFile());
    }

    public function testTripTurnsTheCacheOffAndHoldsItOffAgainstSync(): void
    {
        $switch = $this->switchWith(30, 0);
        $switch->sync();
        self::assertTrue($switch->isOn());

        $switch->trip(600);

        self::assertFalse($switch->isOn());
        self::assertFalse($switch->sync());
        self::assertFalse($switch->isOn());
    }

    public function testALaterShorterTripKeepsTheEarlierLongerDeadline(): void
    {
        $switch = $this->switchWith(30, 0);
        $switch->trip(86400);
        $switch->trip(60);

        self::assertGreaterThanOrEqual(time() + 86000, (int) file_get_contents($this->flagFile.'.tripped'));
    }

    public function testALaterLongerTripExtendsTheDeadline(): void
    {
        $switch = $this->switchWith(30, 0);
        $switch->trip(60);
        $switch->trip(86400);

        self::assertGreaterThanOrEqual(time() + 86000, (int) file_get_contents($this->flagFile.'.tripped'));
    }

    public function testCacheRearmsOnceTheHoldHasExpired(): void
    {
        $switch = $this->switchWith(30, 0);
        mkdir($this->dir);
        file_put_contents($this->flagFile.'.tripped', (string) (time() - 5));

        self::assertTrue($switch->isOn());
        self::assertFileDoesNotExist($this->flagFile.'.tripped');
    }

    public function testSyncRemovesStaleFlagWhenCacheTurnedOff(): void
    {
        mkdir($this->dir);
        touch($this->flagFile);

        $switch = $this->switchWith(0, 0);

        self::assertTrue($switch->isOn());
        $switch->sync();
        self::assertFalse($switch->isOn());
    }
}
