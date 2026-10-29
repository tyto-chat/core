<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\E2eeWelcomeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: E2eeWelcomeRepository::class)]
#[ORM\Table(name: 'e2ee_welcome')]
#[ORM\Index(columns: ['device_id', 'delivered_at'], name: 'IDX_E2EE_WELCOME_DEVICE')]
class E2eeWelcome
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private E2eeDevice $device;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private E2eeGroupEpoch $groupEpoch;

    #[ORM\Column(type: Types::TEXT, length: 16777215)]
    private string $data;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    public function __construct(E2eeDevice $device, E2eeGroupEpoch $groupEpoch, string $data)
    {
        $this->id = Uuid::v4()->toRfc4122();
        $this->device = $device;
        $this->groupEpoch = $groupEpoch;
        $this->data = $data;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDevice(): E2eeDevice
    {
        return $this->device;
    }

    public function getGroupEpoch(): E2eeGroupEpoch
    {
        return $this->groupEpoch;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function markDelivered(): void
    {
        $this->deliveredAt ??= new \DateTimeImmutable();
    }
}
