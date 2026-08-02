<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DataExportRequestRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DataExportRequestRepository::class)]
#[ORM\Index(name: 'data_export_user_status_idx', columns: ['user_id', 'status'])]
class DataExportRequest
{
    public const string STATUS_QUEUED = 'queued';
    public const string STATUS_PROCESSING = 'processing';
    public const string STATUS_READY = 'ready';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_EXPIRED = 'expired';

    public const array ACTIVE_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_PROCESSING,
        self::STATUS_READY,
    ];

    public const int DOWNLOAD_TTL_DAYS = 7;
    public const int COOLDOWN_HOURS = 24;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $readyAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    /** Relative path under APP_EXPORT_DIR. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $filePath = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $fileSize = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $downloadToken = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    public function __construct()
    {
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function setRequestedAt(\DateTimeImmutable $requestedAt): self
    {
        $this->requestedAt = $requestedAt;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getReadyAt(): ?\DateTimeImmutable
    {
        return $this->readyAt;
    }

    public function setReadyAt(?\DateTimeImmutable $readyAt): self
    {
        $this->readyAt = $readyAt;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function setFilePath(?string $filePath): self
    {
        $this->filePath = $filePath;

        return $this;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function setFileSize(?int $fileSize): self
    {
        $this->fileSize = $fileSize;

        return $this;
    }

    public function getDownloadToken(): ?string
    {
        return $this->downloadToken;
    }

    public function setDownloadToken(?string $downloadToken): self
    {
        $this->downloadToken = $downloadToken;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isReady(): bool
    {
        return self::STATUS_READY === $this->status;
    }
}
