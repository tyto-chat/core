<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ChallengeRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

#[ORM\Entity(repositoryClass: ChallengeRepository::class)]
#[ORM\Index(columns: ['email', 'token'])]
class Challenge
{
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    private string $token;

    private ?string $plainToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(options: ['default' => 0])]
    private int $attempts = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function __construct()
    {
        $this->plainToken = sprintf('%06d', random_int(0, 999999));
        $this->token = hash('sha256', $this->plainToken);
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getPlainToken(): ?string
    {
        return $this->plainToken;
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

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function recordFailedAttempt(): static
    {
        ++$this->attempts;

        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }
}
