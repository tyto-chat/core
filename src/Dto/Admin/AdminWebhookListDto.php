<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Serializer\Attribute\Groups;

class AdminWebhookListDto
{
    /** @var AdminWebhookDto[] */
    #[Groups(['admin_webhook:read'])]
    public array $rows = [];
}
