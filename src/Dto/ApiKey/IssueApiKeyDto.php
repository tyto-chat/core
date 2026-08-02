<?php

declare(strict_types=1);

namespace App\Dto\ApiKey;

use App\Enum\ApiKey\ApiKeyScope;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

readonly class IssueApiKeyDto
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 1, max: 64)]
        #[Groups(['api_key:write'])]
        public string $name,
        #[Assert\NotNull]
        #[Assert\Count(min: 1, max: 14, minMessage: 'At least one scope is required.')]
        #[Assert\All([
            new Assert\Choice(callback: [ApiKeyScope::class, 'values']),
        ])]
        #[Groups(['api_key:write'])]
        public array $scopes,
        #[Groups(['api_key:write'])]
        public ?\DateTimeImmutable $expiresAt = null,
    ) {
    }
}
