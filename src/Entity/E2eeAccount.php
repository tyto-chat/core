<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\E2eeAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: E2eeAccountRepository::class)]
#[ORM\Table(name: 'e2ee_account')]
#[ORM\UniqueConstraint(name: 'UNIQ_E2EE_ACCOUNT_USER', columns: ['user_id'])]
class E2eeAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 44)]
    private string $publicKey;

    #[ORM\Column]
    private int $generation = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $identityBackup = null;

    #[ORM\Column(nullable: true)]
    private ?int $identityBackupGeneration = null;

    public function __construct(User $user, string $publicKey)
    {
        $this->user = $user;
        $this->publicKey = $publicKey;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function getGeneration(): int
    {
        return $this->generation;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getIdentityBackup(): ?string
    {
        return $this->identityBackupGeneration === $this->generation ? $this->identityBackup : null;
    }

    public function storeIdentityBackup(string $blob): void
    {
        $this->identityBackup = $blob;
        $this->identityBackupGeneration = $this->generation;
    }

    public function reset(string $publicKey, string $identityBackup): void
    {
        ++$this->generation;
        $this->publicKey = $publicKey;
        $this->storeIdentityBackup($identityBackup);
    }
}
