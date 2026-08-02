<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Serializer\Attribute\Groups;

class AdminAuditPageDto
{
    /** @var AdminAuditRowDto[] */
    #[Groups(['admin_audit:read'])]
    public array $rows = [];

    #[Groups(['admin_audit:read'])]
    public int $total = 0;

    #[Groups(['admin_audit:read'])]
    public int $page = 1;

    #[Groups(['admin_audit:read'])]
    public int $perPage = 25;
}
