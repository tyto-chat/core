<?php

declare(strict_types=1);

namespace App\Tests\Unit\Utils;

use App\Utils\ImagickLimits;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('imagick')]
final class ImagickLimitsTest extends TestCase
{
    /** @var array<int, int> */
    private array $previous = [];

    private static function type(string $name): int
    {
        return (int) \constant(\Imagick::class.'::'.$name);
    }

    private static function limitOf(int $type): int
    {
        return (int) \Imagick::getResourceLimit($type);
    }

    #[\Override]
    protected function setUp(): void
    {
        foreach (array_keys(ImagickLimits::limits()) as $name) {
            $this->previous[self::type($name)] = self::limitOf(self::type($name));
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->previous as $type => $limit) {
            \Imagick::setResourceLimit($type, $limit);
        }
    }

    public function testAppliesEveryBoundedLimit(): void
    {
        ImagickLimits::apply();

        foreach (ImagickLimits::limits() as $name => $limit) {
            self::assertSame($limit, self::limitOf(self::type($name)), $name);
        }
    }

    public function testOverlongImageRaisesACatchableExceptionOnceLimitsApply(): void
    {
        \Imagick::setResourceLimit(self::type('RESOURCETYPE_WIDTH'), 4 * ImagickLimits::MAX_SIDE_PIXELS);
        $wide = new \Imagick();
        $wide->newImage(ImagickLimits::MAX_SIDE_PIXELS + 100, 1, 'white');
        $wide->setImageFormat('png');
        $blob = $wide->getImageBlob();

        ImagickLimits::apply();

        $this->expectException(\ImagickException::class);
        (new \Imagick())->readImageBlob($blob);
    }
}
