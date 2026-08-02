<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Serializer\Attribute\Groups;

class AdminWebhookTriggerDto
{
    #[Groups(['admin_webhook:read'])]
    public string $key = '';

    #[Groups(['admin_webhook:read'])]
    public string $label = '';

    #[Groups(['admin_webhook:read'])]
    public string $description = '';

    /** @var string[] */
    #[Groups(['admin_webhook:read'])]
    public array $filterFields = [];

    /** @var string[] */
    #[Groups(['admin_webhook:read'])]
    public array $requiredFilterFields = [];
}
