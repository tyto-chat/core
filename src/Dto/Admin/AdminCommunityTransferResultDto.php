<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Serializer\Attribute\Groups;

class AdminCommunityTransferResultDto
{
    public function __construct(
        #[Groups(['admin_community:read'])]
        public bool $ok = true,

        #[Groups(['admin_community:read'])]
        public int $demotedCount = 0,
    ) {
    }
}
