<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\E2eeKeyPackageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: E2eeKeyPackageRepository::class)]
#[ORM\Table(name: 'e2ee_key_package')]
#[ORM\Index(columns: ['device_id', 'last_resort', 'expires_at'], name: 'IDX_E2EE_KEY_PACKAGE_DEVICE')]
#[ORM\UniqueConstraint(name: 'UNIQ_E2EE_KEY_PACKAGE_FINGERPRINT', columns: ['device_id', 'fingerprint'])]
class E2eeKeyPackage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private E2eeDevice $device;

    #[ORM\Column(type: Types::TEXT)]
    private string $data;

    #[ORM\Column(length: 64)]
    private string $fingerprint;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column]
    private bool $lastResort;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(E2eeDevice $device, string $data, \DateTimeImmutable $expiresAt, bool $lastResort = false)
    {
        $this->device = $device;
        $this->data = $data;
        $this->fingerprint = self::fingerprintOf($data);
        $this->expiresAt = $expiresAt;
        $this->lastResort = $lastResort;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDevice(): E2eeDevice
    {
        return $this->device;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public static function fingerprintOf(string $data): string
    {
        return hash('sha256', (string) base64_decode($data, true));
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function getClaimedAt(): ?\DateTimeImmutable
    {
        return $this->claimedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isLastResort(): bool
    {
        return $this->lastResort;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
