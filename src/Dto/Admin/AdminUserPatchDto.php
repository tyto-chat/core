<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

// No property defaults — the processor detects supplied fields via isset() on uninitialised typed props.
class AdminUserPatchDto
{
    #[Assert\Length(min: 1, max: 100)]
    #[Groups(['admin_user:write'])]
    public string $displayName;

    #[Groups(['admin_user:write'])]
    public bool $isAdmin;
}
