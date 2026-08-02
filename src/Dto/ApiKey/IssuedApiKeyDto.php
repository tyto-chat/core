<?php

declare(strict_types=1);

namespace App\Dto\ApiKey;

use App\Entity\ApiKey;
use Symfony\Component\Serializer\Attribute\Groups;

final readonly class IssuedApiKeyDto
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        #[Groups(['api_key:read'])]
        public int $id,
        #[Groups(['api_key:read'])]
        public string $name,
        #[Groups(['api_key:read'])]
        public string $prefix,
        #[Groups(['api_key:read'])]
        public array $scopes,
        #[Groups(['api_key:read'])]
        public string $plainToken,
        #[Groups(['api_key:read'])]
        public \DateTimeImmutable $createdAt,
        #[Groups(['api_key:read'])]
        public ?\DateTimeImmutable $expiresAt,
    ) {
    }

    public static function fromEntity(ApiKey $key, string $plainToken): self
    {
        $id = $key->getId();
        if (null === $id) {
            throw new \LogicException('ApiKey must be persisted before being surfaced as an IssuedApiKeyDto.');
        }

        return new self(
            id: $id,
            name: $key->getName(),
            prefix: $key->getPrefix(),
            scopes: $key->getScopes(),
            plainToken: $plainToken,
            createdAt: $key->getCreatedAt(),
            expiresAt: $key->getExpiresAt(),
        );
    }
}
