<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SettingRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SettingRepository::class)]
#[ORM\Table(name: 'setting')]
class Setting
{
    #[ORM\Id]
    #[ORM\Column(name: '`key`', length: 191)]
    private string $key;

    #[ORM\Column(type: 'json', nullable: true)]
    private mixed $value = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    public function __construct(string $key)
    {
        $this->key = $key;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function setValue(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function touch(?User $by): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->updatedBy = $by;

        return $this;
    }
}
