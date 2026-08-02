<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Entity\Webhook;
use Symfony\Component\Serializer\Attribute\Groups;

class AdminWebhookWithSecretDto extends AdminWebhookDto
{
    #[Groups(['admin_webhook:read'])]
    public string $secret = '';

    public static function fromWebhookWithSecret(Webhook $webhook, int $pendingCount, string $secret): self
    {
        $dto = new self();
        self::hydrate($dto, $webhook, $pendingCount);
        $dto->secret = $secret;

        return $dto;
    }
}
