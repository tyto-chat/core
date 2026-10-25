<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\MediaObject;
use App\Service\MediaObject\AttachmentUrlGenerator;
use App\Service\MediaObject\SignedUrlServiceInterface;
use App\Service\Settings\SettingsServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AllowMockObjectsWithoutExpectations]
final class AttachmentUrlGeneratorTest extends TestCase
{
    private string $dir;

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tyto-att-urls-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/attachment_thumb', 0o777, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    private function generator(): AttachmentUrlGenerator
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $params): string => $route.':'.$params['filename']);
        $signer = $this->createMock(SignedUrlServiceInterface::class);
        $signer->method('signStable')->willReturn('tok');
        $settings = $this->createMock(SettingsServiceInterface::class);
        $settings->method('get')->willReturn(3600);

        return new AttachmentUrlGenerator($urls, $signer, $settings, $this->dir);
    }

    private function attachment(string $filePath): MediaObject
    {
        $media = new MediaObject();
        $media->filePath = $filePath;

        return $media;
    }

    public function testThumbnailUrlIsPresentOnlyWhenTheThumbnailFileExists(): void
    {
        touch($this->dir.'/attachment_thumb/a.png');

        self::assertSame('app_media_serve_variant:a.png', $this->generator()->thumbnailUrl($this->attachment('a.png')));
        self::assertNull($this->generator()->thumbnailUrl($this->attachment('b.png')));
    }

    public function testContentUrlIsSignedAndNullWithoutAFile(): void
    {
        self::assertSame('app_media_serve:a.png', $this->generator()->contentUrl($this->attachment('a.png')));
        self::assertNull($this->generator()->contentUrl(new MediaObject()));
        self::assertNull($this->generator()->thumbnailUrl(new MediaObject()));
    }
}
