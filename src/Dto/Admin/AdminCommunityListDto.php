<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Serializer\Attribute\Groups;

class AdminCommunityListDto
{
    /** @var AdminCommunityRowDto[] */
    #[Groups(['admin_community:read'])]
    public array $rows = [];

    #[Groups(['admin_community:read'])]
    public int $total = 0;

    #[Groups(['admin_community:read'])]
    public int $page = 1;

    #[Groups(['admin_community:read'])]
    public int $perPage = 25;
}
