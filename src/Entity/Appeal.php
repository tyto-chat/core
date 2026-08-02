<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\Appeal\CreateAppealDto;
use App\Dto\Appeal\UpdateAppealDto;
use App\Enum\Moderation\AppealStatus;
use App\Repository\AppealRepository;
use App\State\Appeal\Processor\CreateAppealProcessor;
use App\State\Appeal\Processor\UpdateAppealProcessor;
use App\State\Appeal\Provider\AppealProvider;
use App\State\Appeal\Provider\CommunityAppealsProvider;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: AppealRepository::class)]
#[ORM\Index(name: 'idx_appeal_status', columns: ['status'])]
#[ORM\UniqueConstraint(name: 'uniq_appeal_action', columns: ['moderation_action_id'])]
#[ApiResource(
    description: 'A sanctioned user\'s appeal against a moderation action, decided by community staff.',
    normalizationContext: ['groups' => ['appeal:read', 'moderation:read', 'user:embed']],
)]
#[Post(
    uriTemplate: '/moderation-actions/{actionId}/appeals',
    uriVariables: ['actionId' => new Link(fromClass: ModerationAction::class, identifiers: ['id'])],
    security: "is_granted('ROLE_USER')",
    read: false,
    input: CreateAppealDto::class,
    processor: CreateAppealProcessor::class,
    extraProperties: ['scopeResource' => 'moderation'],
    openapi: new Model\Operation(
        summary: 'Appeal a moderation action',
        description: 'Only the target of the moderation action may appeal it; anyone else gets `404`. '
            .'The action must still be active and not already appealed (one appeal per action), otherwise '
            .'`422`. Notifies the community\'s moderators that an appeal was filed.',
    ),
)]
#[GetCollection(
    uriTemplate: '/communities/{community}/appeals',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: CommunityAppealsProvider::class,
    extraProperties: ['scopeResource' => 'moderation'],
    openapi: new Model\Operation(
        summary: 'List a community\'s appeals',
        description: 'Lists appeals against this community\'s moderation actions. Callable by the community\'s '
            .'moderators and admins, or a global admin. Supports `status` and `page` query filters.',
    ),
)]
#[Patch(
    uriTemplate: '/appeals/{id}',
    security: "is_granted('ROLE_USER')",
    input: UpdateAppealDto::class,
    provider: AppealProvider::class,
    processor: UpdateAppealProcessor::class,
    extraProperties: ['scopeResource' => 'moderation'],
    openapi: new Model\Operation(
        summary: 'Decide an appeal',
        description: 'Sets the appeal to `upheld` or `overturned`. Callable by the community\'s moderators and '
            .'admins, a global admin, or a moderator of the channel the action is scoped to. Overturning also '
            .'lifts the underlying action, which additionally requires lift authority for its type (community '
            .'`ban` needs a community admin, `server_ban` a global admin). Already-decided appeals return '
            .'`422`. Notifies the appellant of the outcome.',
    ),
)]
class Appeal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['appeal:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ModerationAction::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['appeal:read'])]
    private ModerationAction $moderationAction;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['appeal:read'])]
    private User $appellant;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['appeal:read'])]
    private string $reason = '';

    #[ORM\Column(length: 20, enumType: AppealStatus::class)]
    #[Groups(['appeal:read'])]
    private AppealStatus $status = AppealStatus::Pending;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['appeal:read'])]
    private ?User $resolvedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['appeal:read'])]
    private ?\DateTimeInterface $resolvedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['appeal:read'])]
    private ?string $resolutionNote = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['appeal:read'])]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getModerationAction(): ModerationAction
    {
        return $this->moderationAction;
    }

    public function setModerationAction(ModerationAction $moderationAction): static
    {
        $this->moderationAction = $moderationAction;

        return $this;
    }

    public function getAppellant(): User
    {
        return $this->appellant;
    }

    public function setAppellant(User $appellant): static
    {
        $this->appellant = $appellant;

        return $this;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function getStatus(): AppealStatus
    {
        return $this->status;
    }

    public function setStatus(AppealStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getResolvedBy(): ?User
    {
        return $this->resolvedBy;
    }

    public function setResolvedBy(?User $resolvedBy): static
    {
        $this->resolvedBy = $resolvedBy;

        return $this;
    }

    public function getResolvedAt(): ?\DateTimeInterface
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeInterface $resolvedAt): static
    {
        $this->resolvedAt = $resolvedAt;

        return $this;
    }

    public function getResolutionNote(): ?string
    {
        return $this->resolutionNote;
    }

    public function setResolutionNote(?string $resolutionNote): static
    {
        $this->resolutionNote = $resolutionNote;

        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
