<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Serializer\Attribute\Groups;

class AdminAuditActorDto
{
    public function __construct(
        #[Groups(['admin_audit:read'])]
        public int $id = 0,
        #[Groups(['admin_audit:read'])]
        public ?string $name = null,
        #[Groups(['admin_audit:read'])]
        public ?string $email = null,
    ) {
    }
}
