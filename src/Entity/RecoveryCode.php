<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RecoveryCodeRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

#[ORM\Entity(repositoryClass: RecoveryCodeRepository::class)]
#[ORM\Table(name: 'two_factor_recovery_code')]
class RecoveryCode
{
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64)]
    private string $codeHash;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getCodeHash(): string
    {
        return $this->codeHash;
    }

    public function setCodeHash(string $codeHash): static
    {
        $this->codeHash = $codeHash;

        return $this;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function setUsedAt(?\DateTimeImmutable $usedAt): static
    {
        $this->usedAt = $usedAt;

        return $this;
    }
}
