<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;

/**
 * `^`-prefixed value = verbatim origin regex; else space/comma list of exact origins; empty matches nothing.
 * The desktop app origin is added on top unless CORS_ALLOW_DESKTOP_APP is 0/false/off/no.
 */
final class CorsOriginEnvVarProcessor implements EnvVarProcessorInterface
{
    public const string DESKTOP_APP_ORIGIN = 'app://tyto';

    private const string DESKTOP_SWITCH = 'CORS_ALLOW_DESKTOP_APP';
    private const string MATCH_NOTHING = '(?!)';
    private const array OFF = ['0', 'false', 'off', 'no'];

    public static function getProvidedTypes(): array
    {
        return ['cors_origin' => 'string'];
    }

    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        $configured = $this->configuredPattern(trim((string) $getEnv($name)));
        if (!$this->allowsDesktopApp($getEnv)) {
            return $configured;
        }

        $desktop = '^'.preg_quote(self::DESKTOP_APP_ORIGIN).'\z';

        return self::MATCH_NOTHING === $configured ? $desktop : '(?:'.$configured.')|'.$desktop;
    }

    private function configuredPattern(string $value): string
    {
        if ('' === $value) {
            return self::MATCH_NOTHING;
        }

        if (str_starts_with($value, '^')) {
            return $value;
        }

        $origins = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (false === $origins || [] === $origins) {
            return self::MATCH_NOTHING;
        }

        $quoted = array_map(
            static fn (string $origin): string => preg_quote(rtrim($origin, '/')),
            $origins,
        );

        return '^(?:'.implode('|', $quoted).')$';
    }

    private function allowsDesktopApp(\Closure $getEnv): bool
    {
        try {
            $switch = strtolower(trim((string) $getEnv(self::DESKTOP_SWITCH)));
        } catch (EnvNotFoundException) {
            return true;
        }

        return !\in_array($switch, self::OFF, true);
    }
}
