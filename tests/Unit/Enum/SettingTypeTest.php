<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\Settings\SettingType;
use App\Enum\Settings\SupportedLocale;
use PHPUnit\Framework\TestCase;

final class SettingTypeTest extends TestCase
{
    public function testStringRoundTrip(): void
    {
        self::assertSame('hi', SettingType::String->encode('hi'));
        self::assertSame('hi', SettingType::String->decode('hi', null));
        self::assertNull(SettingType::String->encode(null));
        self::assertNull(SettingType::String->decode(null, null));
    }

    public function testIntRoundTrip(): void
    {
        self::assertSame(42, SettingType::Int->encode(42));
        self::assertSame(42, SettingType::Int->decode(42, null));
        self::assertNull(SettingType::Int->decode(null, null));
        self::assertSame(7, SettingType::Int->encode(7));
        self::assertNull(SettingType::Int->encode(null));
    }

    public function testBoolRoundTrip(): void
    {
        self::assertTrue(SettingType::Bool->encode(true));
        self::assertFalse(SettingType::Bool->decode(false, null));
        self::assertFalse(SettingType::Bool->encode(false));
        self::assertTrue(SettingType::Bool->decode(true, null));
        self::assertNull(SettingType::Bool->encode(null));
        self::assertTrue(SettingType::Bool->decode('true', null));
    }

    public function testFloatRoundTrip(): void
    {
        self::assertSame(1.5, SettingType::Float->decode(1.5, null));
        self::assertSame(1.5, SettingType::Float->encode(1.5));
        self::assertNull(SettingType::Float->encode(null));
    }

    public function testEnumRoundTrip(): void
    {
        self::assertSame('en', SettingType::Enum->encode(SupportedLocale::English));
        self::assertSame(SupportedLocale::English, SettingType::Enum->decode('en', SupportedLocale::class));
        self::assertNull(SettingType::Enum->decode(null, SupportedLocale::class));
    }

    public function testDateTimeRoundTrip(): void
    {
        $dt = new \DateTimeImmutable('2026-06-04T10:00:00+00:00');
        $encoded = SettingType::DateTime->encode($dt);
        self::assertSame('2026-06-04T10:00:00+00:00', $encoded);
        $decoded = SettingType::DateTime->decode($encoded, null);
        self::assertInstanceOf(\DateTimeImmutable::class, $decoded);
        self::assertSame($dt->getTimestamp(), $decoded->getTimestamp());
        self::assertNull(SettingType::DateTime->decode(null, null));
    }
}
