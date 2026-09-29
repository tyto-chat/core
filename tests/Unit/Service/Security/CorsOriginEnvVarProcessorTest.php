<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Security;

use App\DependencyInjection\CorsOriginEnvVarProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;

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

    public function testAnchoredRegexPassesThroughVerbatimWhenTheDesktopAppIsSwitchedOff(): void
    {
        $regex = '^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$';

        self::assertSame($regex, $this->resolve($regex, '0'));
    }

    public function testAnchoredRegexKeepsMatchingWhatItMatchedBefore(): void
    {
        $pattern = $this->resolve('^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$');

        self::assertSame(1, preg_match('{'.$pattern.'}i', 'http://localhost:5173'));
        self::assertSame(1, preg_match('{'.$pattern.'}i', 'https://127.0.0.1'));
        self::assertSame(0, preg_match('{'.$pattern.'}i', 'https://localhost.evil.net'));
        self::assertSame(0, preg_match('{'.$pattern.'}i', 'https://evil.net'));
    }

    #[DataProvider('operatorValues')]
    public function testTheDesktopAppIsAllowedWhateverTheOperatorConfigured(string $value): void
    {
        $pattern = $this->resolve($value);

        self::assertSame(1, preg_match('{'.$pattern.'}i', 'app://tyto'));
    }

    #[DataProvider('operatorValues')]
    public function testOnlyTheExactDesktopOriginIsAdded(string $value): void
    {
        $pattern = $this->resolve($value);

        foreach ([
            'app://tyto.evil.net',
            'app://tytox',
            'app://evil',
            'xapp://tyto',
            'app://tyto/',
            'https://tyto',
            'https://evil.net/app://tyto',
            "app://tyto\nhttps://evil.net",
            'https://evil.net',
        ] as $origin) {
            self::assertSame(0, preg_match('{'.$pattern.'}i', $origin), $origin);
        }
    }

    public function testAnOriginTheOperatorListedStaysAllowedEvenWithTheSwitchOff(): void
    {
        $pattern = $this->resolve('https://app.example.com app://tyto', '0');

        self::assertSame(1, preg_match('{'.$pattern.'}i', 'app://tyto'));
    }

    public function testATrailingLineBreakDoesNotSneakPast(): void
    {
        $pattern = $this->resolve('https://app.example.com');

        self::assertSame(0, preg_match('{'.$pattern.'}i', "app://tyto\n"));
    }

    #[DataProvider('valuesWithoutTheDesktopOrigin')]
    public function testTheDesktopAppCanBeSwitchedOff(string $value): void
    {
        foreach (['0', 'false', 'off', 'no', ' FALSE '] as $off) {
            $pattern = $this->resolve($value, $off);

            self::assertSame(0, preg_match('{'.$pattern.'}i', 'app://tyto'), $off);
        }
    }

    public function testTheDesktopAppStaysAllowedForAnyOtherSwitchValue(): void
    {
        foreach (['1', 'true', 'on', 'yes', '', 'anything'] as $on) {
            $pattern = $this->resolve('https://app.example.com', $on);

            self::assertSame(1, preg_match('{'.$pattern.'}i', 'app://tyto'), $on);
            self::assertSame(1, preg_match('{'.$pattern.'}i', 'https://app.example.com'), $on);
        }
    }

    public function testTheDesktopAppIsAllowedWhenTheSwitchIsNotDefinedAtAll(): void
    {
        $pattern = $this->processor->getEnv(
            'cors_origin',
            'CORS_ALLOW_ORIGIN',
            static function (string $name): string {
                if ('CORS_ALLOW_ORIGIN' === $name) {
                    return 'https://app.example.com';
                }
                throw new EnvNotFoundException($name);
            },
        );

        self::assertSame(1, preg_match('{'.$pattern.'}i', 'app://tyto'));
        self::assertSame(1, preg_match('{'.$pattern.'}i', 'https://app.example.com'));
    }

    public function testAnOperatorRegexWithAlternationIsNotLoosened(): void
    {
        $pattern = $this->resolve('^https://a\.example$|^https://b\.example$');

        self::assertSame(1, preg_match('{'.$pattern.'}i', 'https://a.example'));
        self::assertSame(1, preg_match('{'.$pattern.'}i', 'https://b.example'));
        self::assertSame(1, preg_match('{'.$pattern.'}i', 'app://tyto'));
        self::assertSame(0, preg_match('{'.$pattern.'}i', 'https://c.example'));
        self::assertSame(0, preg_match('{'.$pattern.'}i', 'https://a.example.evil.net'));
    }

    /** @return iterable<string, array{string}> */
    public static function operatorValues(): iterable
    {
        yield 'nothing configured' => [''];
        yield 'one origin' => ['https://app.example.com'];
        yield 'a list of origins' => ['https://app.example.com, https://chat.other.org'];
        yield 'an anchored regex' => ['^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$'];
        yield 'a regex with alternation' => ['^https://a\.example$|^https://b\.example$'];
        yield 'the desktop origin already listed' => ['https://app.example.com app://tyto'];
    }

    /** @return iterable<string, array{string}> */
    public static function valuesWithoutTheDesktopOrigin(): iterable
    {
        foreach (self::operatorValues() as $name => $case) {
            if (!str_contains($case[0], 'app://tyto')) {
                yield $name => $case;
            }
        }
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

    private function resolve(string $value, string $desktopSwitch = '1'): string
    {
        return $this->processor->getEnv(
            'cors_origin',
            'CORS_ALLOW_ORIGIN',
            static fn (string $name): string => 'CORS_ALLOW_ORIGIN' === $name ? $value : $desktopSwitch,
        );
    }
}
