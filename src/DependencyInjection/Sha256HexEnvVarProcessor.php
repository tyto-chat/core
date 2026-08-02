<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/** `%env(sha256hex:VAR)%` → 64-char lowercase hex SHA-256 — the key format SecretBox expects. */
final class Sha256HexEnvVarProcessor implements EnvVarProcessorInterface
{
    public static function getProvidedTypes(): array
    {
        return ['sha256hex' => 'string'];
    }

    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        return hash('sha256', (string) $getEnv($name));
    }
}
