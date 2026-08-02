<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Serializer\Attribute\Groups;

class TestEmailResultDto
{
    public function __construct(
        #[Groups(['admin_server_config:read'])]
        public bool $ok = false,
        #[Groups(['admin_server_config:read'])]
        public ?string $error = null,
    ) {
    }
}
