<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserBlockRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserBlockRepository::class)]
#[ORM\Table(name: 'user_block')]
#[ORM\UniqueConstraint(name: 'UNIQ_USER_BLOCK_PAIR', columns: ['blocker_id', 'blocked_id'])]
#[ORM\Index(columns: ['blocked_id'], name: 'IDX_USER_BLOCK_BLOCKED')]
class UserBlock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $blocker;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $blocked;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBlocker(): User
    {
        return $this->blocker;
    }

    public function setBlocker(User $blocker): static
    {
        $this->blocker = $blocker;

        return $this;
    }

    public function getBlocked(): User
    {
        return $this->blocked;
    }

    public function setBlocked(User $blocked): static
    {
        $this->blocked = $blocked;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
