<?php

declare(strict_types=1);

namespace App\Tests\Unit\Utils;

use App\Exception\E2ee\InvalidEnvelopeException;
use App\Tests\Functional\E2ee\EnvelopeFixturesTrait;
use App\Utils\E2eeEnvelope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class E2eeEnvelopeTest extends TestCase
{
    use EnvelopeFixturesTrait;

    public function testValidEnvelopePasses(): void
    {
        E2eeEnvelope::assertValid(self::envelope());
        $this->addToAssertionCount(1);
    }

    public function testPrefixDetection(): void
    {
        self::assertTrue(E2eeEnvelope::isEnvelope(self::envelope()));
        self::assertFalse(E2eeEnvelope::isEnvelope('hello e2ee:1:'));
        self::assertFalse(E2eeEnvelope::isEnvelope('e2ee:2:abc'));
    }

    public function testLargestValidEnvelopeUnderCapPasses(): void
    {
        E2eeEnvelope::assertValid(self::envelopeOfBytes(65535));
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEnvelopes(): iterable
    {
        yield 'plain text' => ['hello'];
        yield 'prefix only' => ['e2ee:1:'];
        yield 'not base64url' => ['e2ee:1:abc+/def'];
        yield 'not json' => ['e2ee:1:'.rtrim(strtr(base64_encode('nope'), '+/', '-_'), '=')];
        yield 'json list' => [self::encodeEnvelope(['a', 'b'])];
        yield 'missing key' => [self::encodeEnvelope(['g' => 1, 'n' => 'a', 'c' => 'b'])];
        yield 'extra key' => [self::encodeEnvelope(['g' => 1, 'n' => 'a', 'c' => 'b', 'm' => 'c', 't' => 'leak'])];
        yield 'generation not int' => [self::encodeEnvelope(['g' => '1', 'n' => 'a', 'c' => 'b', 'm' => 'c'])];
        yield 'negative generation' => [self::encodeEnvelope(['g' => -1, 'n' => 'a', 'c' => 'b', 'm' => 'c'])];
        yield 'empty ciphertext' => [self::encodeEnvelope(['g' => 1, 'n' => 'a', 'c' => '', 'm' => 'c'])];
        yield 'ciphertext not string' => [self::encodeEnvelope(['g' => 1, 'n' => 'a', 'c' => ['x'], 'm' => 'c'])];
        yield 'over cap' => ['e2ee:1:'.str_repeat('A', 65530)];
    }

    #[DataProvider('invalidEnvelopes')]
    public function testInvalidEnvelopeRejected(string $text): void
    {
        $this->expectException(InvalidEnvelopeException::class);

        E2eeEnvelope::assertValid($text);
    }
}
