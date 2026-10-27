<?php

declare(strict_types=1);

namespace App\Tests\Unit\E2ee;

use App\E2ee\ByteString;
use App\E2ee\CanonicalCbor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CanonicalCborTest extends TestCase
{
    public function testMapKeysAreSortedByEncodedLengthThenBytes(): void
    {
        $encoded = CanonicalCbor::encodeMap(['bb' => new ByteString("\x01\x02"), 'c' => 'x', 'a' => 1]);

        self::assertSame('a361610161636178626262420102', bin2hex($encoded));
    }

    /** @return iterable<string, array{int, string}> */
    public static function integers(): iterable
    {
        yield 'zero' => [0, '00'];
        yield 'largest single byte' => [23, '17'];
        yield 'one-byte argument' => [24, '1818'];
        yield 'two-byte argument' => [1000, '1903e8'];
        yield 'four-byte argument' => [1_761_553_536, '1a68ff2c80'];
        yield 'eight-byte argument' => [4_294_967_296, '1b0000000100000000'];
    }

    #[DataProvider('integers')]
    public function testUnsignedIntegersUseShortestForm(int $value, string $hex): void
    {
        self::assertSame('a16176'.$hex, bin2hex(CanonicalCbor::encodeMap(['v' => $value])));
    }

    public function testTextIsUtf8ByteLength(): void
    {
        self::assertSame('a1617664c582c3b3', bin2hex(CanonicalCbor::encodeMap(['v' => 'łó'])));
    }

    public function testRejectsNegativeIntegers(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CanonicalCbor::encodeMap(['v' => -1]);
    }

    public function testDeviceCredentialVector(): void
    {
        $encoded = CanonicalCbor::encodeMap([
            'server' => 'https://chat.example',
            'accountId' => 7,
            'deviceId' => '0b8f6f3e-6c1e-4a59-9a3d-2f0f5b8c1d2e',
            'generation' => 1,
            'devicePublicKey' => new ByteString(str_repeat("\xaa", 32)),
        ]);

        self::assertSame(
            'a5'
            .'667365727665727468747470733a2f2f636861742e6578616d706c65'
            .'6864657669636549647824'.bin2hex('0b8f6f3e-6c1e-4a59-9a3d-2f0f5b8c1d2e')
            .'696163636f756e74496407'
            .'6a67656e65726174696f6e01'
            .'6f6465766963655075626c69634b65795820'.str_repeat('aa', 32),
            bin2hex($encoded),
        );
    }
}
