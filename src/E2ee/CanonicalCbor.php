<?php

declare(strict_types=1);

namespace App\E2ee;

final class CanonicalCbor
{
    private const int MAJOR_UNSIGNED = 0;
    private const int MAJOR_BYTES = 2;
    private const int MAJOR_TEXT = 3;
    private const int MAJOR_MAP = 5;

    /** @param array<string, int|string|ByteString> $map */
    public static function encodeMap(array $map): string
    {
        $entries = [];
        foreach ($map as $key => $value) {
            $entries[self::encodeText((string) $key)] = self::encodeValue($value);
        }
        ksort($entries, \SORT_STRING);

        $encoded = self::head(self::MAJOR_MAP, \count($entries));
        foreach ($entries as $key => $value) {
            $encoded .= $key.$value;
        }

        return $encoded;
    }

    private static function encodeValue(int|string|ByteString $value): string
    {
        return match (true) {
            $value instanceof ByteString => self::head(self::MAJOR_BYTES, \strlen($value->bytes)).$value->bytes,
            \is_int($value) => self::encodeUnsigned($value),
            default => self::encodeText($value),
        };
    }

    private static function encodeUnsigned(int $value): string
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('Only unsigned integers are supported.');
        }

        return self::head(self::MAJOR_UNSIGNED, $value);
    }

    private static function encodeText(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException('Text must be valid UTF-8.');
        }

        return self::head(self::MAJOR_TEXT, \strlen($value)).$value;
    }

    private static function head(int $major, int $argument): string
    {
        $type = $major << 5;

        return match (true) {
            $argument < 24 => pack('C', $type | $argument),
            $argument <= 0xFF => pack('CC', $type | 24, $argument),
            $argument <= 0xFFFF => pack('Cn', $type | 25, $argument),
            $argument <= 0xFFFFFFFF => pack('CN', $type | 26, $argument),
            default => pack('CJ', $type | 27, $argument),
        };
    }
}
