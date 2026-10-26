<?php

declare(strict_types=1);

namespace App\Utils;

final class GifFrames
{
    private const string EXTENSION = "\x21";
    private const string IMAGE = "\x2C";
    private const int COLOUR_TABLE_FLAG = 0x80;

    public static function isAnimated(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            return false;
        }

        try {
            return self::countFrames($handle, 2) > 1;
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private static function countFrames($handle, int $stopAt): int
    {
        $header = self::read($handle, 13);
        if (null === $header || !\in_array(substr($header, 0, 6), ['GIF87a', 'GIF89a'], true)) {
            return 0;
        }
        if (!self::skipColourTable($handle, \ord($header[10]))) {
            return 0;
        }

        $frames = 0;
        while (null !== ($introducer = self::read($handle, 1))) {
            if (self::EXTENSION === $introducer) {
                if (!self::skip($handle, 1) || !self::skipSubBlocks($handle)) {
                    break;
                }
                continue;
            }
            if (self::IMAGE !== $introducer) {
                break;
            }

            $descriptor = self::read($handle, 9);
            if (null === $descriptor || !self::skipColourTable($handle, \ord($descriptor[8])) || !self::skip($handle, 1) || !self::skipSubBlocks($handle)) {
                break;
            }
            if (++$frames >= $stopAt) {
                break;
            }
        }

        return $frames;
    }

    /** @param resource $handle */
    private static function skipColourTable($handle, int $packed): bool
    {
        if (0 === ($packed & self::COLOUR_TABLE_FLAG)) {
            return true;
        }

        return self::skip($handle, 3 * (2 ** (($packed & 0x07) + 1)));
    }

    /** @param resource $handle */
    private static function skipSubBlocks($handle): bool
    {
        while (null !== ($size = self::read($handle, 1))) {
            if ("\x00" === $size) {
                return true;
            }
            if (!self::skip($handle, \ord($size))) {
                return false;
            }
        }

        return false;
    }

    /** @param resource $handle */
    private static function skip($handle, int $length): bool
    {
        return null !== self::read($handle, $length);
    }

    /** @param resource $handle */
    private static function read($handle, int $length): ?string
    {
        if ($length < 1) {
            return '';
        }
        $bytes = fread($handle, $length);

        return false !== $bytes && \strlen($bytes) === $length ? $bytes : null;
    }
}
