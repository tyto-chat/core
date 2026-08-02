<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Security;

use App\DependencyInjection\CorsOriginEnvVarProcessor;
use PHPUnit\Framework\TestCase;

final class CorsOriginEnvVarProcessorTest extends TestCase
{
    private CorsOriginEnvVarProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new CorsOriginEnvVarProcessor();
    }

    public function testEmptyValueMatchesNoOrigin(): void
    {
        $pattern = $this->resolve('');

        self::assertSame(0, preg_match('{'.$pattern.'}i', 'https://app.example.com'));
        self::assertSame(0, preg_match('{'.$pattern.'}i', ''));
    }

    public function testAnchoredRegexPassesThroughVerbatim(): void
    {
        $regex = '^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$';

        self::assertSame($regex, $this->resolve($regex));
    }

    public function testSingleOriginCompilesToAnchoredQuotedRegex(): void
    {
        $pattern = $this->resolve('https://app.example.com');

        self::assertSame(1, preg_match('{'.$pattern.'}i', 'https://app.example.com'));
        self::assertSame(0, preg_match('{'.$pattern.'}i', 'https://appxexample.com'));
        self::assertSame(0, preg_match('{'.$pattern.'}i', 'https://app.example.com.evil.net'));
        self::assertSame(0, preg_match('{'.$pattern.'}i', 'https://evil.net/https://app.example.com'));
    }

    public function testOriginListSplitsOnSpacesAndCommas(): void
    {
        $pattern = $this->resolve('https://app.example.com, https://chat.other.org https://third.example');

        foreach (['https://app.example.com', 'https://chat.other.org', 'https://third.example'] as $origin) {
            self::assertSame(1, preg_match('{'.$pattern.'}i', $origin));
        }
        self::assertSame(0, preg_match('{'.$pattern.'}i', 'https://fourth.example'));
    }

    public function testTrailingSlashesAreStripped(): void
    {
        $pattern = $this->resolve('https://app.example.com/');

        self::assertSame(1, preg_match('{'.$pattern.'}i', 'https://app.example.com'));
    }

    private function resolve(string $value): string
    {
        return $this->processor->getEnv('cors_origin', 'CORS_ALLOW_ORIGIN', static fn (): string => $value);
    }
}
