<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Security;

use App\DependencyInjection\HmacKeyEnvVarProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

final class HmacKeyEnvVarProcessorTest extends TestCase
{
    private HmacKeyEnvVarProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new HmacKeyEnvVarProcessor();
    }

    public function testKeyOfExactlyTheMinimumLengthPassesThrough(): void
    {
        $key = str_repeat('a', HmacKeyEnvVarProcessor::MIN_BYTES);

        self::assertSame($key, $this->resolve($key));
    }

    public function testLongerKeyPassesThroughUnchanged(): void
    {
        $key = bin2hex(random_bytes(32));

        self::assertSame($key, $this->resolve($key));
    }

    public function testShortKeyIsRejectedNamingTheVariableAndItsLength(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"LIVEKIT_API_SECRET" is 11 bytes');

        $this->resolve('test_secret', 'LIVEKIT_API_SECRET');
    }

    public function testEmptyKeyIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is 0 bytes');

        $this->resolve('');
    }

    public function testLengthIsCountedInBytesNotCharacters(): void
    {
        $key = str_repeat('é', 20);

        self::assertSame(40, \strlen($key));
        self::assertSame($key, $this->resolve($key));
    }

    private function resolve(string $value, string $name = 'SOME_KEY'): string
    {
        return $this->processor->getEnv('hmac_key', $name, static fn (): string => $value);
    }
}
