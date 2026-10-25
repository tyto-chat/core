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
    private string $flagFile;

    #[\Override]
    protected function setUp(): void
    {
        $this->flagFile = sys_get_temp_dir().'/tyto-http-cache-'.bin2hex(random_bytes(4)).'.on';
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->flagFile);
        @unlink($this->flagFile.'.lock');
        @unlink($this->flagFile.'.tripped');
    }

    private function switchWith(int $pageTtl, int $presenceTtl): HttpCacheSwitch
    {
        $settings = self::createStub(SettingsServiceInterface::class);
        $settings->method('get')->willReturnCallback(
            static fn ($def): int => $def->key === Settings::httpCachePageTtlSeconds()->key ? $pageTtl : $presenceTtl,
        );

        return new HttpCacheSwitch($settings, new Filesystem(), $this->flagFile);
    }

    /** @return iterable<string, array{int, int, bool}> */
    public static function ttlCombinations(): iterable
    {
        yield 'both off' => [0, 0, false];
        yield 'pages on' => [30, 0, true];
        yield 'presence on' => [0, 15, true];
        yield 'both on' => [30, 15, true];
    }

    #[DataProvider('ttlCombinations')]
    public function testSyncMirrorsTtlSettingsIntoFlagFile(int $pageTtl, int $presenceTtl, bool $expected): void
    {
        $switch = $this->switchWith($pageTtl, $presenceTtl);

        self::assertSame($expected, $switch->sync());
        self::assertSame($expected, $switch->isOn());
        self::assertSame($expected, is_file($this->flagFile));
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
        @unlink($this->flagFile.'.tripped');
    }

    public function testCacheRearmsOnceTheHoldHasExpired(): void
    {
        $switch = $this->switchWith(30, 0);
        file_put_contents($this->flagFile.'.tripped', (string) (time() - 5));

        self::assertTrue($switch->isOn());
        self::assertFileDoesNotExist($this->flagFile.'.tripped');
    }

    public function testSyncRemovesStaleFlagWhenCacheTurnedOff(): void
    {
        touch($this->flagFile);

        $switch = $this->switchWith(0, 0);

        self::assertTrue($switch->isOn());
        $switch->sync();
        self::assertFalse($switch->isOn());
    }
}
