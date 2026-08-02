<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Serializer\Attribute\Groups;

class AdminAuditActionsDto
{
    /** @var string[] */
    #[Groups(['admin_audit:read'])]
    public array $actions = [];
}
