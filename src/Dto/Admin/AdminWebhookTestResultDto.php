<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Serializer\Attribute\Groups;

class AdminWebhookTestResultDto
{
    public function __construct(
        #[Groups(['admin_webhook:read'])]
        public int $deliveryId = 0,

        #[Groups(['admin_webhook:read'])]
        public string $status = '',
    ) {
    }
}
