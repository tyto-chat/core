<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Webhook\WebhookDeliveryStatus;
use App\Repository\WebhookDeliveryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WebhookDeliveryRepository::class)]
#[ORM\Index(columns: ['created_at'])]
class WebhookDelivery
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Webhook::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Webhook $webhook;

    #[ORM\Column(length: 64)]
    private string $triggerKey = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $actor = null;

    /**
     * @var array<string,mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $requestPayload = [];

    #[ORM\Column(length: 16, enumType: WebhookDeliveryStatus::class)]
    private WebhookDeliveryStatus $status = WebhookDeliveryStatus::Pending;

    #[ORM\Column(nullable: true)]
    private ?int $httpCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $responseBody = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $error = null;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string,mixed> $requestPayload
     */
    public function __construct(
        Webhook $webhook,
        string $triggerKey,
        ?User $actor,
        array $requestPayload,
        WebhookDeliveryStatus $status,
    ) {
        $this->webhook = $webhook;
        $this->triggerKey = $triggerKey;
        $this->actor = $actor;
        $this->requestPayload = $requestPayload;
        $this->status = $status;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWebhook(): Webhook
    {
        return $this->webhook;
    }

    public function getTriggerKey(): string
    {
        return $this->triggerKey;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    /**
     * @return array<string,mixed>
     */
    public function getRequestPayload(): array
    {
        return $this->requestPayload;
    }

    public function getStatus(): WebhookDeliveryStatus
    {
        return $this->status;
    }

    public function setStatus(WebhookDeliveryStatus $status): static
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getHttpCode(): ?int
    {
        return $this->httpCode;
    }

    public function setHttpCode(?int $httpCode): static
    {
        $this->httpCode = $httpCode;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    public function setResponseBody(?string $responseBody): static
    {
        $this->responseBody = $responseBody;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): static
    {
        $this->error = $error;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): static
    {
        $this->attempts = $attempts;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function incrementAttempts(): void
    {
        ++$this->attempts;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function setDurationMs(?int $durationMs): static
    {
        $this->durationMs = $durationMs;
        $this->updatedAt = new \DateTimeImmutable();

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
}
