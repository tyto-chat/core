<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Serializer\Attribute\Groups;

class AdminUserPageDto
{
    /** @var AdminUserRowDto[] */
    #[Groups(['admin_user:read'])]
    public array $rows = [];

    #[Groups(['admin_user:read'])]
    public int $total = 0;

    #[Groups(['admin_user:read'])]
    public int $page = 1;

    #[Groups(['admin_user:read'])]
    public int $perPage = 25;
}
