<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Serializer\Attribute\Groups;

class AdminWebhookReplayResultDto
{
    public function __construct(
        #[Groups(['admin_webhook:read'])]
        public int $replayed = 0,
    ) {
    }
}
