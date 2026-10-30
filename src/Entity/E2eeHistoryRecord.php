<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\E2eeHistoryRecordRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: E2eeHistoryRecordRepository::class)]
#[ORM\Table(name: 'e2ee_history_record')]
#[ORM\UniqueConstraint(name: 'UNIQ_E2EE_HISTORY_DEVICE_COUNTER', columns: ['device_id', 'counter'])]
#[ORM\Index(columns: ['account_id', 'device_id', 'counter'], name: 'IDX_E2EE_HISTORY_ACCOUNT_ORDER')]
class E2eeHistoryRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private E2eeAccount $account;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private E2eeDevice $device;

    #[ORM\Column]
    private int $counter;

    #[ORM\Column(name: 'data', type: Types::TEXT)]
    private string $blob;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(E2eeDevice $device, int $counter, string $blob)
    {
        $this->account = $device->getAccount();
        $this->device = $device;
        $this->counter = $counter;
        $this->blob = $blob;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): E2eeAccount
    {
        return $this->account;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDevice(): E2eeDevice
    {
        return $this->device;
    }

    public function getCounter(): int
    {
        return $this->counter;
    }

    public function getBlob(): string
    {
        return $this->blob;
    }
}
