<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\User\RequestPasswordResetDto;
use App\Repository\ResetPasswordRequestRepository;
use App\State\User\Processor\ResetPasswordRequestProcessor;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;

#[ORM\Entity(repositoryClass: ResetPasswordRequestRepository::class)]
#[ORM\Index(columns: ['email', 'token'])]
#[ApiResource(
    description: 'A time-limited password-reset token emailed to an address.',
    operations: [
        new Post(
            uriTemplate: '/reset_password',
            input: RequestPasswordResetDto::class,
            output: false,
            processor: ResetPasswordRequestProcessor::class,
            openapi: new Model\Operation(
                summary: 'Request a password-reset email',
                description: 'Anonymous. Creates a time-limited reset token and emails a reset link to '
                    .'the given address. Always responds with success — whether the address belongs to '
                    .'an account is never disclosed (the token is only usable if it does).',
            ),
        ),
    ],
)]
class ResetPasswordRequest
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

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    public function __construct()
    {
        $this->plainToken = bin2hex(random_bytes(16));
        $this->token = hash('sha256', $this->plainToken);
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function getPlainToken(): ?string
    {
        return $this->plainToken;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function setUsedAt(?\DateTimeImmutable $usedAt): void
    {
        $this->usedAt = $usedAt;
    }
}
