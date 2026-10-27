<?php

declare(strict_types=1);

namespace App\Tests\Unit\E2ee;

use App\E2ee\KeyPackageUploadPayload;
use PHPUnit\Framework\TestCase;

final class KeyPackageUploadPayloadTest extends TestCase
{
    public function testEncodesTheSignedUploadMap(): void
    {
        $one = ['data' => base64_encode("\x01\x02"), 'expiresAt' => 1_800_000_000];
        $two = ['data' => base64_encode("\x03"), 'expiresAt' => 1_800_000_001];
        $lastResort = ['data' => base64_encode("\x04\x05\x06"), 'expiresAt' => 1_900_000_000];

        $packagesDigest = hash('sha256', hash('sha256', "\x01\x02", true).pack('J', 1_800_000_000).hash('sha256', "\x03", true).pack('J', 1_800_000_001), true);
        $lastResortDigest = hash('sha256', hash('sha256', "\x04\x05\x06", true).pack('J', 1_900_000_000), true);
        $expected = "\xa5"
            ."\x68deviceId\x64dev1"
            ."\x68packages\x58\x20".$packagesDigest
            ."\x68replaces\x58\x20".str_repeat("\x07", 32)
            ."\x6ageneration\x02"
            ."\x6alastResort\x58\x20".$lastResortDigest;

        self::assertSame(bin2hex($expected), bin2hex(KeyPackageUploadPayload::encode('dev1', 2, [$one, $two], $lastResort, str_repeat("\x07", 32))));
    }

    public function testAbsentLastResortSignsTheDigestOfAnEmptyList(): void
    {
        $encoded = KeyPackageUploadPayload::encode('dev1', 1, [], null, null);

        self::assertStringEndsWith("\x6alastResort\x58\x20".hash('sha256', '', true), $encoded);
        self::assertStringContainsString("\x68packages\x58\x20".hash('sha256', '', true), $encoded);
        self::assertStringContainsString("\x68replaces\x40", $encoded);
    }
}
