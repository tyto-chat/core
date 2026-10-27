<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\E2eeDeviceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: E2eeDeviceRepository::class)]
#[ORM\Table(name: 'e2ee_device')]
#[ORM\Index(columns: ['account_id', 'revoked_at'], name: 'IDX_E2EE_DEVICE_ACCOUNT_ACTIVE')]
class E2eeDevice
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private E2eeAccount $account;

    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(length: 44)]
    private string $publicKey;

    #[ORM\Column(length: 255)]
    private string $server;

    #[ORM\Column]
    private int $generation;

    #[ORM\Column(length: 88)]
    private string $credential;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /** @var array{signerDeviceId: string, revokedAt: int, signature: string}|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $revocation = null;

    public function __construct(string $id, E2eeAccount $account, string $name, string $publicKey, string $server, int $generation, string $credential)
    {
        $this->server = $server;
        $this->id = $id;
        $this->account = $account;
        $this->name = $name;
        $this->publicKey = $publicKey;
        $this->generation = $generation;
        $this->credential = $credential;
        $this->createdAt = new \DateTimeImmutable();
        $this->lastSeenAt = $this->createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAccount(): E2eeAccount
    {
        return $this->account;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function getServer(): string
    {
        return $this->server;
    }

    public function getGeneration(): int
    {
        return $this->generation;
    }

    public function getCredential(): string
    {
        return $this->credential;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    /** @return array{signerDeviceId: string, revokedAt: int, signature: string}|null */
    public function getRevocation(): ?array
    {
        return $this->revocation;
    }

    /** @param array{signerDeviceId: string, revokedAt: int, signature: string} $revocation */
    public function revoke(array $revocation): void
    {
        $this->revocation = $revocation;
        $this->revokedAt = new \DateTimeImmutable();
    }
}
