<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Serializer\Attribute\Groups;

class AdminCommunityRowDto
{
    #[Groups(['admin_community:read'])]
    public int $id = 0;

    #[Groups(['admin_community:read'])]
    public string $identifier = '';

    #[Groups(['admin_community:read'])]
    public string $name = '';

    #[Groups(['admin_community:read'])]
    public ?string $description = null;

    #[Groups(['admin_community:read'])]
    public bool $isPrivate = false;

    #[Groups(['admin_community:read'])]
    public int $memberCount = 0;

    #[Groups(['admin_community:read'])]
    public int $messageCount = 0;

    /** ISO 8601, null for legacy rows with no creation timestamp. */
    #[Groups(['admin_community:read'])]
    public ?string $createdAt = null;
}
