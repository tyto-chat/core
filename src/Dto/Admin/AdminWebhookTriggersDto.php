<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Serializer\Attribute\Groups;

class AdminWebhookTriggersDto
{
    /** @var AdminWebhookTriggerDto[] */
    #[Groups(['admin_webhook:read'])]
    public array $triggers = [];
}
