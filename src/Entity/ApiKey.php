<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\ApiKey\IssueApiKeyDto;
use App\Dto\ApiKey\IssuedApiKeyDto;
use App\Repository\ApiKeyRepository;
use App\State\ApiKey\Processor\IssueApiKeyProcessor;
use App\State\ApiKey\Processor\RevokeApiKeyProcessor;
use App\State\ApiKey\Provider\ApiKeysProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: ApiKeyRepository::class)]
#[ORM\Index(name: 'api_key_user_active_idx', columns: ['user_id', 'revoked_at'])]
#[ApiResource(
    description: 'A Personal Access Token (PAT) for programmatic API access, bound to a single user.',
    operations: [
        new GetCollection(
            uriTemplate: '/me/api-keys',
            security: "is_granted('ROLE_USER')",
            provider: ApiKeysProvider::class,
            openapi: new Model\Operation(
                summary: 'List the caller\'s API keys',
                description: 'The authenticated user. Returns all of the caller\'s keys, including '
                    .'revoked and expired ones. Only the 12-character `prefix` is exposed — the '
                    .'plaintext token is never retrievable after issuance.',
            ),
        ),
        new Post(
            uriTemplate: '/me/api-keys',
            security: "is_granted('ROLE_USER')",
            input: IssueApiKeyDto::class,
            output: IssuedApiKeyDto::class,
            processor: IssueApiKeyProcessor::class,
            openapi: new Model\Operation(
                summary: 'Issue a new API key',
                description: 'The authenticated user. Creates a key with the given name, scopes and '
                    .'optional expiry; the response contains the plaintext `pat_…` token exactly once '
                    .'and it can never be retrieved again. `admin`-scoped keys are forced to expire '
                    .'within 90 days. `422` when no scope is given or a scope is not allowed for the '
                    .'caller.',
            ),
        ),
        new Delete(
            uriTemplate: '/me/api-keys/{id}',
            security: "is_granted('API_KEY_MANAGE', object)",
            processor: RevokeApiKeyProcessor::class,
            openapi: new Model\Operation(
                summary: 'Revoke an API key',
                description: 'The key\'s owner or a global admin. Marks the key revoked so it no '
                    .'longer authenticates; the row is kept and stays listed. Revoking an '
                    .'already-revoked key is a no-op.',
            ),
        ),
    ],
    normalizationContext: ['groups' => ['api_key:read']],
    denormalizationContext: ['groups' => ['api_key:write']],
)]
class ApiKey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['api_key:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64)]
    #[Groups(['api_key:read'])]
    private string $name;

    #[ORM\Column(length: 12)]
    #[Groups(['api_key:read'])]
    private string $prefix;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    #[Groups(['api_key:read'])]
    private array $scopes = [];

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['api_key:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['api_key:read'])]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['api_key:read'])]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['api_key:read'])]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function setPrefix(string $prefix): self
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): self
    {
        $this->tokenHash = $tokenHash;

        return $this;
    }

    /** @return list<string> */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /** @param list<string> $scopes */
    public function setScopes(array $scopes): self
    {
        $this->scopes = $scopes;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?\DateTimeImmutable $lastUsedAt): self
    {
        $this->lastUsedAt = $lastUsedAt;

        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?\DateTimeImmutable $revokedAt): self
    {
        $this->revokedAt = $revokedAt;

        return $this;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        if (null === $this->expiresAt) {
            return false;
        }

        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    public function isActive(?\DateTimeImmutable $now = null): bool
    {
        return !$this->isRevoked() && !$this->isExpired($now);
    }
}
