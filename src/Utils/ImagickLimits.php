<?php

declare(strict_types=1);

namespace App\Utils;

final class ImagickLimits
{
    public const int MEMORY_BYTES = 256 * 1024 * 1024;
    public const int MAP_BYTES = 512 * 1024 * 1024;
    public const int DISK_BYTES = 1024 * 1024 * 1024;
    public const int MAX_SIDE_PIXELS = 16384;

    /** @return array<string, int> resource constant name => limit */
    public static function limits(): array
    {
        return [
            'RESOURCETYPE_MEMORY' => self::MEMORY_BYTES,
            'RESOURCETYPE_MAP' => self::MAP_BYTES,
            'RESOURCETYPE_DISK' => self::DISK_BYTES,
            'RESOURCETYPE_WIDTH' => self::MAX_SIDE_PIXELS,
            'RESOURCETYPE_HEIGHT' => self::MAX_SIDE_PIXELS,
        ];
    }

    public static function apply(): void
    {
        if (!class_exists(\Imagick::class)) {
            return;
        }

        foreach (self::limits() as $name => $limit) {
            $constant = \Imagick::class.'::'.$name;
            if (\defined($constant)) {
                \Imagick::setResourceLimit((int) \constant($constant), $limit);
            }
        }
    }
}
