<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Serializer\Attribute\Groups;

class AdminWebhookSecretDto
{
    public function __construct(
        #[Groups(['admin_webhook:read'])]
        public string $secret = '',
    ) {
    }
}
