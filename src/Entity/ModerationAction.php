<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\Moderation\CreateModerationActionDto;
use App\Enum\Moderation\ModerationActionType;
use App\Repository\ModerationActionRepository;
use App\State\Moderation\Processor\CreateModerationActionProcessor;
use App\State\Moderation\Processor\LiftModerationActionProcessor;
use App\State\Moderation\Provider\ActiveModerationProvider;
use App\State\Moderation\Provider\ModerationActionProvider;
use App\State\Moderation\Provider\ModerationLogProvider;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: ModerationActionRepository::class)]
#[ORM\Index(name: 'idx_moderation_target_type_lifted', columns: ['target_user_id', 'type', 'lifted_at'])]
#[ApiFilter(SearchFilter::class, properties: ['type' => 'exact'])]
#[ApiResource(
    description: 'A moderation sanction (`warn`, `timeout`, `ban`, or `server_ban`) taken against a user, logged per community.',
    normalizationContext: ['groups' => ['moderation:read', 'user:embed']],
)]
#[Post(
    uriTemplate: '/communities/{community}/moderation',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    read: false,
    input: CreateModerationActionDto::class,
    processor: CreateModerationActionProcessor::class,
    extraProperties: ['scopeResource' => 'moderation'],
    openapi: new Model\Operation(
        summary: 'Take a moderation action',
        description: 'Sanctions a user per the authority ladder: `warn` needs a community moderator or above; '
            .'`timeout` needs a moderator of the given channel when `channelIdentifier` is set, otherwise a '
            .'community moderator; `ban` needs a community admin and also kicks the target and lifts their '
            .'active timeouts; `server_ban` needs a global admin, applies app-wide, and disconnects the '
            .'target from voice. Bots, global admins, and community admins are immune (`403`). Notifies the '
            .'target and emits a `moderation.action` webhook event.',
    ),
)]
#[GetCollection(
    uriTemplate: '/communities/{community}/moderation',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: ModerationLogProvider::class,
    extraProperties: ['scopeResource' => 'moderation'],
    openapi: new Model\Operation(
        summary: 'List the community moderation log',
        description: 'Community moderators, community admins, and global admins see the full log; a channel '
            .'moderator sees only actions scoped to their channel. Supports `type`, `active`, `targetUserId`, '
            .'and `page` query filters; an unknown `targetUserId` returns an empty list.',
    ),
)]
#[Get(
    uriTemplate: '/communities/{community}/moderation/{id}',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'id' => new Link(fromClass: ModerationAction::class, identifiers: ['id']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: ModerationActionProvider::class,
    extraProperties: ['scopeResource' => 'moderation'],
    openapi: new Model\Operation(
        summary: 'Get a moderation action',
        description: 'Fetches a single moderation action from this community\'s log. Callable by the '
            .'community\'s moderators and admins, a global admin, or a moderator of the channel the action '
            .'is scoped to.',
    ),
)]
#[Delete(
    uriTemplate: '/communities/{community}/moderation/{id}',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'id' => new Link(fromClass: ModerationAction::class, identifiers: ['id']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: ModerationActionProvider::class,
    processor: LiftModerationActionProcessor::class,
    extraProperties: ['scopeResource' => 'moderation'],
    openapi: new Model\Operation(
        summary: 'Lift a moderation action',
        description: 'Marks the action lifted (the row stays in the log) and notifies the target. Authority '
            .'depends on the type: timeouts can be lifted by a community moderator or the action\'s channel '
            .'moderator, a community `ban` needs a community admin, and a `server_ban` only a global admin.',
    ),
)]
#[GetCollection(
    uriTemplate: '/communities/{community}/users/{userId}/active-moderation',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'userId' => new Link(fromClass: User::class, identifiers: ['id']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: ActiveModerationProvider::class,
    extraProperties: ['scopeResource' => 'moderation'],
    openapi: new Model\Operation(
        summary: 'List a user\'s active moderation actions',
        description: 'Returns the unexpired, unlifted actions against a user in this community. Users may read '
            .'their own status; community moderators and admins (or a global admin) see all actions, while a '
            .'channel moderator sees only actions scoped to their channel.',
    ),
)]
class ModerationAction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['moderation:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Community::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Community $community;

    #[ORM\ManyToOne(targetEntity: Channel::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Channel $channel = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['moderation:read'])]
    private User $targetUser;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['moderation:read'])]
    private User $actorUser;

    #[ORM\Column(length: 20, enumType: ModerationActionType::class)]
    #[Groups(['moderation:read'])]
    private ModerationActionType $type;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['moderation:read'])]
    private ?string $reason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['moderation:read'])]
    private ?\DateTimeInterface $expiresAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['moderation:read'])]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['moderation:read'])]
    private ?\DateTimeInterface $liftedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['moderation:read'])]
    private ?User $liftedBy = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function warn(Community $community, User $target, User $actor, ?string $reason): self
    {
        $action = new self();
        $action->community = $community;
        $action->targetUser = $target;
        $action->actorUser = $actor;
        $action->type = ModerationActionType::Warn;
        $action->reason = $reason;

        return $action;
    }

    public static function timeout(
        Community $community,
        User $target,
        User $actor,
        ?string $reason,
        \DateTimeInterface $expiresAt,
        ?Channel $channel = null,
    ): self {
        $action = new self();
        $action->community = $community;
        $action->channel = $channel;
        $action->targetUser = $target;
        $action->actorUser = $actor;
        $action->type = ModerationActionType::Timeout;
        $action->reason = $reason;
        $action->expiresAt = $expiresAt;

        return $action;
    }

    public static function ban(
        Community $community,
        User $target,
        User $actor,
        ?string $reason,
        ?\DateTimeInterface $expiresAt,
    ): self {
        $action = new self();
        $action->community = $community;
        $action->targetUser = $target;
        $action->actorUser = $actor;
        $action->type = ModerationActionType::Ban;
        $action->reason = $reason;
        $action->expiresAt = $expiresAt;

        return $action;
    }

    public static function serverBan(
        Community $community,
        User $target,
        User $actor,
        ?string $reason,
        ?\DateTimeInterface $expiresAt,
    ): self {
        $action = new self();
        $action->community = $community;
        $action->targetUser = $target;
        $action->actorUser = $actor;
        $action->type = ModerationActionType::ServerBan;
        $action->reason = $reason;
        $action->expiresAt = $expiresAt;

        return $action;
    }

    public function lift(User $by): void
    {
        $this->liftedAt = new \DateTimeImmutable();
        $this->liftedBy = $by;
    }

    public function isLifted(): bool
    {
        return null !== $this->liftedAt;
    }

    #[Groups(['moderation:read'])]
    public function getCommunityIdentifier(): ?string
    {
        return $this->community->getIdentifier();
    }

    #[Groups(['moderation:read'])]
    public function getChannelIdentifier(): ?string
    {
        return $this->channel?->getIdentifier();
    }

    #[Groups(['moderation:read'])]
    public function isActive(): bool
    {
        if (null !== $this->liftedAt) {
            return false;
        }

        if (null !== $this->expiresAt && $this->expiresAt <= new \DateTimeImmutable()) {
            return false;
        }

        return true;
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

    public function getChannel(): ?Channel
    {
        return $this->channel;
    }

    public function setChannel(?Channel $channel): static
    {
        $this->channel = $channel;

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

    public function getActorUser(): User
    {
        return $this->actorUser;
    }

    public function setActorUser(User $actorUser): static
    {
        $this->actorUser = $actorUser;

        return $this;
    }

    public function getType(): ModerationActionType
    {
        return $this->type;
    }

    public function setType(ModerationActionType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeInterface $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getLiftedAt(): ?\DateTimeInterface
    {
        return $this->liftedAt;
    }

    public function setLiftedAt(?\DateTimeInterface $liftedAt): static
    {
        $this->liftedAt = $liftedAt;

        return $this;
    }

    public function getLiftedBy(): ?User
    {
        return $this->liftedBy;
    }

    public function setLiftedBy(?User $liftedBy): static
    {
        $this->liftedBy = $liftedBy;

        return $this;
    }
}
