<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Entity\ApiKey;
use Symfony\Component\Serializer\Attribute\Groups;

class AdminApiKeyRowDto
{
    #[Groups(['admin_user:read'])]
    public int $id = 0;

    #[Groups(['admin_user:read'])]
    public ?string $name = null;

    #[Groups(['admin_user:read'])]
    public ?string $prefix = null;

    /** @var string[] */
    #[Groups(['admin_user:read'])]
    public array $scopes = [];

    #[Groups(['admin_user:read'])]
    public string $createdAt = '';

    #[Groups(['admin_user:read'])]
    public ?string $expiresAt = null;

    #[Groups(['admin_user:read'])]
    public ?string $lastUsedAt = null;

    #[Groups(['admin_user:read'])]
    public ?string $revokedAt = null;

    public static function fromApiKey(ApiKey $key): self
    {
        $row = new self();
        $row->id = (int) $key->getId();
        $row->name = $key->getName();
        $row->prefix = $key->getPrefix();
        $row->scopes = $key->getScopes();
        $row->createdAt = $key->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $row->expiresAt = $key->getExpiresAt()?->format(\DateTimeInterface::ATOM);
        $row->lastUsedAt = $key->getLastUsedAt()?->format(\DateTimeInterface::ATOM);
        $row->revokedAt = $key->getRevokedAt()?->format(\DateTimeInterface::ATOM);

        return $row;
    }
}
