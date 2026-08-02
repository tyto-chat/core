<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\Moderation\CreateModeratorNoteDto;
use App\Dto\Moderation\UpdateModeratorNoteDto;
use App\Repository\ModeratorNoteRepository;
use App\State\Moderation\Processor\CreateModeratorNoteProcessor;
use App\State\Moderation\Processor\DeleteModeratorNoteProcessor;
use App\State\Moderation\Processor\UpdateModeratorNoteProcessor;
use App\State\Moderation\Provider\ModeratorNoteProvider;
use App\State\Moderation\Provider\ModeratorNotesProvider;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: ModeratorNoteRepository::class)]
#[ApiResource(
    description: 'A private staff-only note about a user, kept per community and never shown to the user.',
    normalizationContext: ['groups' => ['note:read', 'user:embed']],
)]
#[Post(
    uriTemplate: '/communities/{community}/users/{userId}/notes',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'userId' => new Link(fromClass: User::class, identifiers: ['id']),
    ],
    security: "is_granted('ROLE_USER')",
    read: false,
    input: CreateModeratorNoteDto::class,
    processor: CreateModeratorNoteProcessor::class,
    extraProperties: ['scopeResource' => 'moderation'],
    openapi: new Model\Operation(
        summary: 'Add a moderator note about a user',
        description: 'Attaches a private staff note to a user within this community. Callable by the '
            .'community\'s moderators and admins, or a global admin. The note is never visible to the '
            .'target user.',
    ),
)]
#[GetCollection(
    uriTemplate: '/communities/{community}/users/{userId}/notes',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'userId' => new Link(fromClass: User::class, identifiers: ['id']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: ModeratorNotesProvider::class,
    extraProperties: ['scopeResource' => 'moderation'],
    openapi: new Model\Operation(
        summary: 'List moderator notes about a user',
        description: 'Lists the staff notes kept about a user in this community. Callable by the community\'s '
            .'moderators and admins, or a global admin.',
    ),
)]
#[Patch(
    uriTemplate: '/communities/{community}/notes/{id}',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'id' => new Link(fromClass: ModeratorNote::class, identifiers: ['id']),
    ],
    security: "is_granted('ROLE_USER')",
    input: UpdateModeratorNoteDto::class,
    provider: ModeratorNoteProvider::class,
    processor: UpdateModeratorNoteProcessor::class,
    extraProperties: ['scopeResource' => 'moderation'],
    openapi: new Model\Operation(
        summary: 'Edit a moderator note',
        description: 'Replaces the note\'s content and bumps its update timestamp. Callable by a global admin, '
            .'a community admin, or the note\'s author; other community moderators can read but not edit it.',
    ),
)]
#[Delete(
    uriTemplate: '/communities/{community}/notes/{id}',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'id' => new Link(fromClass: ModeratorNote::class, identifiers: ['id']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: ModeratorNoteProvider::class,
    processor: DeleteModeratorNoteProcessor::class,
    extraProperties: ['scopeResource' => 'moderation'],
    openapi: new Model\Operation(
        summary: 'Delete a moderator note',
        description: 'Permanently removes the note. Callable by a global admin, a community admin, or the '
            .'note\'s author; other community moderators can read but not delete it.',
    ),
)]
class ModeratorNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['note:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Community::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Community $community;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['note:read'])]
    private User $targetUser;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['note:read'])]
    private User $author;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['note:read'])]
    private string $content;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['note:read'])]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['note:read'])]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[Groups(['note:read'])]
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

    public function getTargetUser(): User
    {
        return $this->targetUser;
    }

    public function setTargetUser(User $targetUser): static
    {
        $this->targetUser = $targetUser;

        return $this;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function setAuthor(User $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }
}
