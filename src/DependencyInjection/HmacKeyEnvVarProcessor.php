<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/** `%env(hmac_key:VAR)%` → the value, rejected unless it can sign HS256 (>= 32 bytes). */
final class HmacKeyEnvVarProcessor implements EnvVarProcessorInterface
{
    public const int MIN_BYTES = 32;

    public static function getProvidedTypes(): array
    {
        return ['hmac_key' => 'string'];
    }

    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        $value = (string) $getEnv($name);
        $length = \strlen($value);

        if ($length < self::MIN_BYTES) {
            throw new RuntimeException(\sprintf('Environment variable "%s" is %d bytes; HS256 signing requires at least %d. Generate one with: openssl rand -hex 32', $name, $length, self::MIN_BYTES));
        }

        return $value;
    }
}
