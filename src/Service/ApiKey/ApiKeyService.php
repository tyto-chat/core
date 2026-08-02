<?php

declare(strict_types=1);

namespace App\Service\ApiKey;

use App\Dto\ApiKey\IssuedApiKey;
use App\Entity\ApiKey;
use App\Entity\User;
use App\Enum\ApiKey\ApiKeyScope;
use App\Exception\ApiKey\EmptyScopesException;
use App\Repository\ApiKeyRepository;
use App\Security\SecurityContext;
use App\Service\AbstractDoctrineService;

class ApiKeyService extends AbstractDoctrineService implements ApiKeyServiceInterface
{
    // Tokens are hashed SHA-256 on purpose — per-request lookup needs a fast hash; 256-bit entropy makes brute force infeasible.
    public const string TOKEN_PREFIX = 'pat_';

    public const int ADMIN_KEY_MAX_DAYS = 90;

    public const int LAST_USED_THROTTLE_SECONDS = 60;

    public function __construct(
        private readonly SecurityContext $security,
        private readonly ApiKeyRepository $repository,
        private readonly ScopeIssuancePolicyInterface $scopePolicy,
    ) {
    }

    /** @return ApiKey[] */
    #[\Override]
    public function listForCurrentUser(): array
    {
        $user = $this->security->currentUser('You must be signed in to list API keys.');

        return $this->repository->findByUser($user);
    }

    /** @param list<string> $scopes */
    #[\Override]
    public function issue(string $name, array $scopes, ?\DateTimeImmutable $expiresAt = null): IssuedApiKey
    {
        $user = $this->security->currentUser('You must be signed in to issue an API key.');

        return $this->issueFor($user, $name, $scopes, $expiresAt);
    }

    /** @param list<string> $scopes */
    #[\Override]
    public function issueFor(User $target, string $name, array $scopes, ?\DateTimeImmutable $expiresAt = null): IssuedApiKey
    {
        $caller = $this->security->currentUser('You must be signed in to issue an API key.');
        $this->security->throwAccessDeniedIf($target->getId() !== $caller->getId() && !$this->security->isAdmin(), 'Only admins can issue API keys for other users.');

        if (0 === count($scopes)) {
            throw new EmptyScopesException('At least one scope is required.');
        }
        $scopes = array_values(array_unique($scopes));
        $this->scopePolicy->assertAllowed($target, $scopes);

        // Admin-scoped keys are takeover-grade — force a bounded lifetime so a leaked one auto-expires.
        if (in_array(ApiKeyScope::Admin->value, $scopes, true)) {
            $maxExpiry = new \DateTimeImmutable(sprintf('+%d days', self::ADMIN_KEY_MAX_DAYS));
            if (null === $expiresAt || $expiresAt > $maxExpiry) {
                $expiresAt = $maxExpiry;
            }
        }

        $plainToken = self::TOKEN_PREFIX.self::randomTokenBody();

        $key = new ApiKey();
        $key->setUser($target)
            ->setName($name)
            ->setPrefix(substr($plainToken, 0, 12))
            ->setTokenHash(hash('sha256', $plainToken))
            ->setScopes($scopes)
            ->setExpiresAt($expiresAt);

        $this->persist($key);
        $this->flush();

        $this->logger?->info('api_key.issued', [
            'channel' => 'api_key',
            'key_id' => $key->getId(),
            'user_id' => $target->getId(),
            'prefix' => $key->getPrefix(),
            'scopes' => $scopes,
            'expires_at' => $expiresAt?->format(\DateTimeInterface::ATOM),
        ]);

        return new IssuedApiKey($key, $plainToken);
    }

    #[\Override]
    public function countFor(User $user): int
    {
        return count($this->repository->findByUser($user));
    }

    #[\Override]
    public function revokeAllFor(User $user): void
    {
        foreach ($this->repository->findByUser($user) as $key) {
            $this->revoke($key);
        }
    }

    #[\Override]
    public function revoke(ApiKey $key): void
    {
        if ($key->isRevoked()) {
            return;
        }
        $key->setRevokedAt(new \DateTimeImmutable());
        $this->flush();

        $this->logger?->info('api_key.revoked', [
            'channel' => 'api_key',
            'key_id' => $key->getId(),
            'user_id' => $key->getUser()->getId(),
            'prefix' => $key->getPrefix(),
        ]);
    }

    #[\Override]
    public function findByPlainToken(string $plainToken): ?ApiKey
    {
        if (!str_starts_with($plainToken, self::TOKEN_PREFIX)) {
            return null;
        }

        return $this->repository->findOneByTokenHash(hash('sha256', $plainToken));
    }

    #[\Override]
    public function touchLastUsed(ApiKey $key): void
    {
        $now = new \DateTimeImmutable();
        $last = $key->getLastUsedAt();
        if (null !== $last && $now->getTimestamp() - $last->getTimestamp() < self::LAST_USED_THROTTLE_SECONDS) {
            return;
        }

        $key->setLastUsedAt($now);
        $this->flush();
    }

    private static function randomTokenBody(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
