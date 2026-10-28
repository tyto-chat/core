<?php

declare(strict_types=1);

namespace App\Utils;

use App\Exception\E2ee\InvalidEnvelopeException;

final class E2eeEnvelope
{
    public const string PREFIX = 'e2ee:1:';
    public const int MAX_BYTES = 65536;
    private const array KEYS = ['c', 'g', 'm', 'n'];

    public static function isEnvelope(string $text): bool
    {
        return str_starts_with($text, self::PREFIX);
    }

    public static function assertValid(string $text): void
    {
        if (!self::isEnvelope($text) || \strlen($text) > self::MAX_BYTES) {
            throw new InvalidEnvelopeException();
        }

        $encoded = substr($text, \strlen(self::PREFIX));
        if (1 !== preg_match('/^[A-Za-z0-9_-]+$/', $encoded)) {
            throw new InvalidEnvelopeException();
        }

        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        $fields = false === $json ? null : json_decode($json, true);
        if (!\is_array($fields) || array_is_list($fields)) {
            throw new InvalidEnvelopeException();
        }

        $keys = array_keys($fields);
        sort($keys);
        if (self::KEYS !== $keys
            || !\is_int($fields['g']) || $fields['g'] < 0
            || !self::isNonEmptyString($fields['n'])
            || !self::isNonEmptyString($fields['c'])
            || !self::isNonEmptyString($fields['m'])
        ) {
            throw new InvalidEnvelopeException();
        }
    }

    private static function isNonEmptyString(mixed $value): bool
    {
        return \is_string($value) && '' !== $value;
    }
}
