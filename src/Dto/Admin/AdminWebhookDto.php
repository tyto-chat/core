<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Entity\Webhook;
use Symfony\Component\Serializer\Attribute\Groups;

class AdminWebhookDto
{
    #[Groups(['admin_webhook:read'])]
    public int $id = 0;

    #[Groups(['admin_webhook:read'])]
    public ?string $name = null;

    #[Groups(['admin_webhook:read'])]
    public ?string $url = null;

    #[Groups(['admin_webhook:read'])]
    public ?string $triggerKey = null;

    /** @var array<string, mixed>|null */
    #[Groups(['admin_webhook:read'])]
    public ?array $filters = null;

    #[Groups(['admin_webhook:read'])]
    public bool $isActive = false;

    #[Groups(['admin_webhook:read'])]
    public ?string $disabledReason = null;

    #[Groups(['admin_webhook:read'])]
    public int $pendingCount = 0;

    #[Groups(['admin_webhook:read'])]
    public string $createdAt = '';

    #[Groups(['admin_webhook:read'])]
    public string $updatedAt = '';

    public static function fromWebhook(Webhook $webhook, int $pendingCount): self
    {
        $dto = new self();
        self::hydrate($dto, $webhook, $pendingCount);

        return $dto;
    }

    protected static function hydrate(self $dto, Webhook $webhook, int $pendingCount): void
    {
        $dto->id = (int) $webhook->getId();
        $dto->name = $webhook->getName();
        $dto->url = $webhook->getUrl();
        $dto->triggerKey = $webhook->getTriggerKey();
        $dto->filters = $webhook->getFilters();
        $dto->isActive = $webhook->isActive();
        $dto->disabledReason = $webhook->getDisabledReason();
        $dto->pendingCount = $pendingCount;
        $dto->createdAt = $webhook->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $dto->updatedAt = $webhook->getUpdatedAt()->format(\DateTimeInterface::ATOM);
    }
}
