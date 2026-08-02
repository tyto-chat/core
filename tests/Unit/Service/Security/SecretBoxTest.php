<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Security;

use App\Service\Security\SecretBox;
use PHPUnit\Framework\TestCase;

class SecretBoxTest extends TestCase
{
    private function box(): SecretBox
    {
        return new SecretBox(str_repeat('a', 64)); // 64 hex chars = 32 bytes
    }

    public function testRoundTrip(): void
    {
        $box = $this->box();
        $cipher = $box->encrypt('hunter2');
        self::assertNotSame('hunter2', $cipher);
        self::assertSame('hunter2', $box->decrypt($cipher));
    }

    public function testCiphertextIsNonDeterministic(): void
    {
        $box = $this->box();
        self::assertNotSame($box->encrypt('x'), $box->encrypt('x'));
    }

    public function testDecryptGarbageReturnsNull(): void
    {
        self::assertNull($this->box()->decrypt('not-valid-cipher'));
    }

    public function testRejectsWrongKeyLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SecretBox('tooshort');
    }
}
