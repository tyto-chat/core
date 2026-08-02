<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Serializer\Attribute\Groups;

class AdminAuditRowDto
{
    #[Groups(['admin_audit:read'])]
    public int $id = 0;

    #[Groups(['admin_audit:read'])]
    public ?AdminAuditActorDto $actor = null;

    #[Groups(['admin_audit:read'])]
    public string $action = '';

    #[Groups(['admin_audit:read'])]
    public ?string $targetType = null;

    #[Groups(['admin_audit:read'])]
    public ?int $targetId = null;

    /** @var array<string, mixed>|null */
    #[Groups(['admin_audit:read'])]
    public ?array $payload = null;

    /** ISO 8601. */
    #[Groups(['admin_audit:read'])]
    public string $createdAt = '';
}
