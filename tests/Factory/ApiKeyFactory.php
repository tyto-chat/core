<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Enum\ApiKey\ApiKeyScope;
use App\Service\ApiKey\ApiKeyService;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<ApiKey>
 */
final class ApiKeyFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return ApiKey::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        $plain = ApiKeyService::TOKEN_PREFIX.bin2hex(random_bytes(22));

        return [
            'user' => UserFactory::new(),
            'name' => 'test key',
            'prefix' => substr($plain, 0, 12),
            'tokenHash' => hash('sha256', $plain),
            // Default scope set keeps Phase-2-style auth tests calling `/api/me`
            // working without per-test scope wiring. Scope-specific tests pass
            // an explicit `scopes` override.
            'scopes' => [ApiKeyScope::ProfileRead->value],
        ];
    }

    /**
     * Create a key for the given user and return the plaintext token alongside.
     * Use this in tests that need to drive `Authorization: Bearer <token>`
     * (the persisted entity stores only the SHA-256 hash, so the plaintext is
     * not recoverable after creation).
     *
     * @param array<string, mixed> $overrides forwarded to the factory (e.g. `expiresAt`, `revokedAt`, `scopes`)
     *
     * @return array{key: ApiKey, plainToken: string}
     */
    public static function createWithToken(User $user, array $overrides = []): array
    {
        $plain = ApiKeyService::TOKEN_PREFIX.bin2hex(random_bytes(22));

        $key = self::createOne(array_merge([
            'user' => $user,
            'prefix' => substr($plain, 0, 12),
            'tokenHash' => hash('sha256', $plain),
        ], $overrides));

        return ['key' => $key, 'plainToken' => $plain];
    }
}
