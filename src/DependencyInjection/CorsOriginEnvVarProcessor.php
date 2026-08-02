<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/** `^`-prefixed value = verbatim origin regex; else space/comma list of exact origins; empty compiles to `(?!)` (matches nothing). */
final class CorsOriginEnvVarProcessor implements EnvVarProcessorInterface
{
    public static function getProvidedTypes(): array
    {
        return ['cors_origin' => 'string'];
    }

    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        $value = trim((string) $getEnv($name));
        if ('' === $value) {
            return '(?!)';
        }

        if (str_starts_with($value, '^')) {
            return $value;
        }

        $origins = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (false === $origins || [] === $origins) {
            return '(?!)';
        }

        $quoted = array_map(
            static fn (string $origin): string => preg_quote(rtrim($origin, '/')),
            $origins,
        );

        return '^(?:'.implode('|', $quoted).')$';
    }
}
