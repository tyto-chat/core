<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\Community\CreateCommunityInviteDto;
use App\Repository\CommunityInviteRepository;
use App\State\Community\Processor\CreateCommunityInviteProcessor;
use App\State\Community\Processor\DeleteCommunityInviteProcessor;
use App\State\Community\Provider\CommunityInviteProvider;
use App\State\Community\Provider\CommunityInvitesProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

#[ORM\Entity(repositoryClass: CommunityInviteRepository::class)]
#[ApiResource(
    description: 'A shareable invite token that lets a user join a community, with optional expiry and use limit.',
    normalizationContext: ['groups' => ['invite:read']],
)]
#[Post(
    uriTemplate: '/communities/{community}/invites',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    read: false,
    input: CreateCommunityInviteDto::class,
    processor: CreateCommunityInviteProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Create a community invite',
        description: 'Requires community admin. Generates a random invite token with optional '
            .'`maxUses` and `expiresAt` limits.',
    ),
)]
#[GetCollection(
    uriTemplate: '/communities/{community}/invites',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: CommunityInvitesProvider::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'List community invites',
        description: 'Requires community admin. Returns every invite of the community, including '
            .'expired or exhausted ones (`isValid` reflects current usability).',
    ),
)]
#[Delete(
    uriTemplate: '/communities/{community}/invites/{id}',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'id' => new Link(fromClass: CommunityInvite::class, identifiers: ['id']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: CommunityInviteProvider::class,
    processor: DeleteCommunityInviteProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Revoke a community invite',
        description: 'Requires community admin. Deletes the invite so its token can no longer be '
            .'accepted. An invite id not belonging to the community returns `404`.',
    ),
)]
class CommunityInvite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['invite:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Community::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Community $community;

    #[ORM\Column(length: 64, unique: true)]
    #[Groups(['invite:read'])]
    private string $token;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['invite:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['invite:read'])]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['invite:read'])]
    private ?int $maxUses = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['invite:read'])]
    private int $useCount = 0;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[Groups(['invite:read'])]
    #[SerializedName('isValid')]
    public function isValid(): bool
    {
        if (null !== $this->expiresAt && $this->expiresAt < new \DateTimeImmutable()) {
            return false;
        }

        return null === $this->maxUses || $this->useCount < $this->maxUses;
    }

    #[Groups(['invite:read'])]
    public function getCommunityIdentifier(): ?string
    {
        return $this->community->getIdentifier();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCommunity(): Community
    {
        return $this->community;
    }

    public function setCommunity(Community $community): static
    {
        $this->community = $community;

        return $this;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function setToken(string $token): static
    {
        $this->token = $token;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getMaxUses(): ?int
    {
        return $this->maxUses;
    }

    public function setMaxUses(?int $maxUses): static
    {
        $this->maxUses = $maxUses;

        return $this;
    }

    public function getUseCount(): int
    {
        return $this->useCount;
    }

    public function setUseCount(int $useCount): static
    {
        $this->useCount = $useCount;

        return $this;
    }

    public function incrementUseCount(): static
    {
        ++$this->useCount;

        return $this;
    }
}
