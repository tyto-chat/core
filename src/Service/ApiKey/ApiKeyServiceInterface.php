<?php

declare(strict_types=1);

namespace App\Service\ApiKey;

use App\Dto\ApiKey\IssuedApiKey;
use App\Entity\ApiKey;
use App\Entity\User;

interface ApiKeyServiceInterface
{
    /**
     * @return ApiKey[]
     */
    public function listForCurrentUser(): array;

    /**
     * @param list<string> $scopes
     */
    public function issueFor(User $target, string $name, array $scopes, ?\DateTimeImmutable $expiresAt = null): IssuedApiKey;

    /**
     * @param list<string> $scopes
     */
    public function issue(string $name, array $scopes, ?\DateTimeImmutable $expiresAt = null): IssuedApiKey;

    public function revoke(ApiKey $key): void;

    // Caller enforces authz — no check inside.
    public function revokeAllFor(User $user): void;

    public function countFor(User $user): int;

    // Returns revoked/expired keys too — caller must check active state.
    public function findByPlainToken(string $plainToken): ?ApiKey;

    public function touchLastUsed(ApiKey $key): void;
}
