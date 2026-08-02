<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WebhookRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WebhookRepository::class)]
#[ORM\Index(columns: ['trigger_key'])]
class Webhook
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 2048)]
    private string $url = '';

    #[ORM\Column(length: 64)]
    private string $triggerKey = '';

    /**
     * @var array<string,mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $filters = null;

    #[ORM\Column(length: 80)]
    private string $secret = '';

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $disabledReason = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getTriggerKey(): string
    {
        return $this->triggerKey;
    }

    public function setTriggerKey(string $triggerKey): static
    {
        $this->triggerKey = $triggerKey;

        return $this;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getFilters(): ?array
    {
        return $this->filters;
    }

    /**
     * @param array<string,mixed>|null $filters
     */
    public function setFilters(?array $filters): static
    {
        $this->filters = $filters;

        return $this;
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function setSecret(string $secret): static
    {
        $this->secret = $secret;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getDisabledReason(): ?string
    {
        return $this->disabledReason;
    }

    public function setDisabledReason(?string $disabledReason): static
    {
        $this->disabledReason = $disabledReason;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
