<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Serializer\Attribute\Groups;

class AdminUserApiKeysDto
{
    /** @var AdminApiKeyRowDto[] */
    #[Groups(['admin_user:read'])]
    public array $rows = [];
}
