<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Serializer\Attribute\Groups;

class AdminWebhookDeliveryPageDto
{
    /** @var AdminWebhookDeliveryDto[] */
    #[Groups(['admin_webhook:read'])]
    public array $rows = [];

    #[Groups(['admin_webhook:read'])]
    public int $page = 1;

    #[Groups(['admin_webhook:read'])]
    public int $perPage = 25;

    #[Groups(['admin_webhook:read'])]
    public int $replayableCount = 0;
}
