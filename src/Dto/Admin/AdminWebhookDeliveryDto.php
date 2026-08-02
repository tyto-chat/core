<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Entity\WebhookDelivery;
use Symfony\Component\Serializer\Attribute\Groups;

class AdminWebhookDeliveryDto
{
    #[Groups(['admin_webhook:read'])]
    public int $id = 0;

    #[Groups(['admin_webhook:read'])]
    public ?string $triggerKey = null;

    #[Groups(['admin_webhook:read'])]
    public string $status = '';

    #[Groups(['admin_webhook:read'])]
    public ?int $httpCode = null;

    #[Groups(['admin_webhook:read'])]
    public ?string $error = null;

    #[Groups(['admin_webhook:read'])]
    public int $attempts = 0;

    #[Groups(['admin_webhook:read'])]
    public ?int $durationMs = null;

    /** ISO 8601. */
    #[Groups(['admin_webhook:read'])]
    public string $createdAt = '';

    /** ISO 8601. */
    #[Groups(['admin_webhook:read'])]
    public string $updatedAt = '';

    public static function fromDelivery(WebhookDelivery $delivery): self
    {
        $dto = new self();
        $dto->id = (int) $delivery->getId();
        $dto->triggerKey = $delivery->getTriggerKey();
        $dto->status = $delivery->getStatus()->value;
        $dto->httpCode = $delivery->getHttpCode();
        $dto->error = $delivery->getError();
        $dto->attempts = $delivery->getAttempts();
        $dto->durationMs = $delivery->getDurationMs();
        $dto->createdAt = $delivery->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $dto->updatedAt = $delivery->getUpdatedAt()->format(\DateTimeInterface::ATOM);

        return $dto;
    }
}
