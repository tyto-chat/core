<?php

declare(strict_types=1);

namespace App\Tests\Unit\Utils;

use App\Utils\GifFrames;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GifFramesTest extends TestCase
{
    private const string FRAME = ",\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x01\x00\x00";
    private const string GRAPHIC_CONTROL = "!\xf9\x04\x00\x0a\x00\x00\x00";
    private const string HEADER = "GIF89a\x01\x00\x01\x00\x00\x00\x00";

    /** @return iterable<string, array{string, bool}> */
    public static function gifs(): iterable
    {
        yield 'static' => [self::HEADER.self::FRAME.';', false];
        yield 'looping animation' => [self::HEADER."!\xff\x0bNETSCAPE2.0\x03\x01\x00\x00\x00".self::GRAPHIC_CONTROL.self::FRAME.self::GRAPHIC_CONTROL.self::FRAME.';', true];
        yield 'play-once animation without NETSCAPE2.0' => [self::HEADER.self::GRAPHIC_CONTROL.self::FRAME.self::GRAPHIC_CONTROL.self::FRAME.';', true];
        yield 'global colour table before the frames' => ["GIF89a\x01\x00\x01\x00\x80\x00\x00".str_repeat("\x00", 6).self::FRAME.self::FRAME.';', true];
        yield 'local colour table on a frame' => [self::HEADER.",\x00\x00\x00\x00\x01\x00\x01\x00\x80".str_repeat("\x00", 6)."\x02\x01\x00\x00".';', false];
        yield 'comment holding a fake image separator' => [self::HEADER."!\xfe\x01,\x00".self::FRAME.';', false];
        yield 'truncated after the first frame' => [self::HEADER.self::FRAME, false];
        yield 'not a gif' => ["\x89PNG\r\n\x1a\n", false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('gifs')]
    public function testDetectsMoreThanOneFrame(string $bytes, bool $animated): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gif');
        file_put_contents($path, $bytes);

        try {
            self::assertSame($animated, GifFrames::isAnimated($path));
        } finally {
            unlink($path);
        }
    }

    public function testMissingFileIsNotAnimated(): void
    {
        self::assertFalse(GifFrames::isAnimated('/nonexistent/file.gif'));
    }

    public function testRealImagickAnimationIsDetected(): void
    {
        if (!class_exists(\Imagick::class)) {
            self::markTestSkipped('imagick not installed');
        }
        $animation = new \Imagick();
        foreach (['red', 'blue', 'green'] as $colour) {
            $frame = new \Imagick();
            $frame->newImage(40, 30, $colour);
            $frame->setImageFormat('gif');
            $animation->addImage($frame);
        }
        $animation->setFormat('gif');
        $path = tempnam(sys_get_temp_dir(), 'gif');
        file_put_contents($path, $animation->getImagesBlob());

        try {
            self::assertTrue(GifFrames::isAnimated($path));
        } finally {
            unlink($path);
        }
    }
}
