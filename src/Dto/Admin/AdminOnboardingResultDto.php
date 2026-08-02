<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Serializer\Attribute\Groups;

class AdminOnboardingResultDto
{
    public function __construct(
        #[Groups(['admin_server_config:read'])]
        public bool $adminOnboardingComplete = true,
        #[Groups(['admin_server_config:read'])]
        public ?string $adminOnboardedAt = null,
    ) {
    }
}
