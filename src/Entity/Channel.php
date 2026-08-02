<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model;
use App\Dto\Channel\CreateChannelDto;
use App\Dto\Channel\SetChannelPinStateDto;
use App\Dto\Channel\UpdateChannelDto;
use App\Dto\Notification\SetChannelLevelDto;
use App\Dto\Voice\VoiceCallTokenDto;
use App\Enum\Channel\ChannelType;
use App\Repository\ChannelRepository;
use App\State\Channel\Processor\AddChannelMemberProcessor;
use App\State\Channel\Processor\ArchiveChannelProcessor;
use App\State\Channel\Processor\CreateChannelProcessor;
use App\State\Channel\Processor\DeleteChannelProcessor;
use App\State\Channel\Processor\MarkChannelReadProcessor;
use App\State\Channel\Processor\PublishChannelTypingProcessor;
use App\State\Channel\Processor\RemoveChannelMemberProcessor;
use App\State\Channel\Processor\SetChannelNotificationLevelProcessor;
use App\State\Channel\Processor\SetChannelPinStateProcessor;
use App\State\Channel\Processor\UnarchiveChannelProcessor;
use App\State\Channel\Processor\UpdateChannelProcessor;
use App\State\Channel\Provider\ChannelProvider;
use App\State\Voice\Processor\CreateVoiceTokenProcessor;
use App\State\Voice\Processor\LeaveVoiceCallProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Blameable\Traits\BlameableEntity;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ChannelRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_CHANNEL_IDENTIFIER', fields: ['identifier'])]
#[ApiResource(
    description: 'A text or audio channel belonging to a community.',
    normalizationContext: ['groups' => ['channel:read']],
    denormalizationContext: ['groups' => ['channel:create', 'channel:update']],
    mercure: ['topics' => ['@=object.getCommunityIri()'], 'private' => true],
)]
#[Post(
    security: "is_granted('ROLE_USER')",
    input: CreateChannelDto::class,
    processor: CreateChannelProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Create a channel',
        description: 'Requires community admin of the target community. Creates a text or audio channel, appends it '
            .'to the chosen section and publishes a `community.structure` Mercure update. Returns `403` when '
            .'`type` is `audio` but voice is disabled on the server.',
    ),
)]
#[Get(
    uriTemplate: '/communities/{community}/channels/{channel}',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'channel' => new Link(fromClass: Channel::class, identifiers: ['identifier']),
    ],
    provider: ChannelProvider::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Get a channel',
        description: 'Returns a single channel by community and channel identifier. Anyone may read a public channel '
            .'in a public community (including anonymously); private channels require channel membership (direct or '
            .'via a group grant), community moderator/admin, or global admin. Returns `404` when the channel does '
            .'not exist in that community.',
    ),
)]
#[Patch(
    uriTemplate: '/communities/{community}/channels/{channel}',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'channel' => new Link(fromClass: Channel::class, identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    input: UpdateChannelDto::class,
    provider: ChannelProvider::class,
    processor: UpdateChannelProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Update a channel',
        description: 'Requires community admin. Updates channel settings (name, description, section, privacy, '
            .'read-only flags, attachments) and publishes a `community.structure` Mercure update. Returns `422` '
            .'when the target section is null or belongs to another community.',
    ),
)]
#[Put(
    uriTemplate: '/communities/{community}/channels/{channel}',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'channel' => new Link(fromClass: Channel::class, identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    input: UpdateChannelDto::class,
    provider: ChannelProvider::class,
    processor: UpdateChannelProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Replace a channel',
        description: 'Requires community admin. Same behavior as the PATCH update: applies channel settings and '
            .'publishes a `community.structure` Mercure update. Returns `422` when the target section is null or '
            .'belongs to another community.',
    ),
)]
#[Delete(
    uriTemplate: '/communities/{community}/channels/{channel}',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'channel' => new Link(fromClass: Channel::class, identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: ChannelProvider::class,
    processor: DeleteChannelProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Delete a channel',
        description: 'Requires community admin. Deletes the channel with all its message pages, disconnects any '
            .'active voice participants asynchronously and publishes a `community.structure` Mercure update.',
    ),
)]
#[Post(
    uriTemplate: '/communities/{community}/channels/{channel}/members',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'channel' => new Link(fromClass: Channel::class, identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    input: \App\Dto\Channel\AddChannelMemberDto::class,
    denormalizationContext: ['groups' => ['channel_member:add']],
    read: false,
    processor: AddChannelMemberProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Add a channel member',
        description: 'Requires channel moderator, community moderator/admin, or global admin. Adds a community '
            .'member to the channel with role `member` or `moderator` (updates the role if already a member), '
            .'notifies the user and publishes a `community.structure` Mercure update; on private channels also '
            .'emits a `channel.access.granted` user event. Returns `422` when the target user is not a member of '
            .'the community.',
    ),
)]
#[Delete(
    uriTemplate: '/communities/{community}/channels/{channel}/members/{userId}',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'channel' => new Link(fromClass: Channel::class, identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: ChannelProvider::class,
    processor: RemoveChannelMemberProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Remove a channel member',
        description: 'Callable by the member themselves, a community admin, or (on private channels) a channel '
            .'moderator; moderators cannot remove other moderators. Publishes a `community.structure` Mercure '
            .'update; on private channels also emits a `channel.access.revoked` user event and disconnects the '
            .'user from the channel voice room. Returns `404` when the user is not a member.',
    ),
)]
#[Post(
    uriTemplate: '/communities/{community}/channels/{channel}/call/token',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'channel' => new Link(fromClass: Channel::class, identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    input: false,
    output: VoiceCallTokenDto::class,
    provider: ChannelProvider::class,
    processor: CreateVoiceTokenProcessor::class,
    normalizationContext: ['groups' => ['voice_call_token:read']],
    openapi: new Model\Operation(
        summary: 'Join a voice call',
        description: 'Any user who may view the channel. Joins the caller to the audio channel (recording a '
            .'participant row), publishes the updated participant list over Mercure, re-evaluates presence, and '
            .'returns a LiveKit room token plus the server URL. Returns `404` when the channel is not an audio '
            .'channel and `403` when voice is disabled on the server.',
    ),
)]
#[Delete(
    uriTemplate: '/communities/{community}/channels/{channel}/call',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'channel' => new Link(fromClass: Channel::class, identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: ChannelProvider::class,
    processor: LeaveVoiceCallProcessor::class,
    openapi: new Model\Operation(
        summary: 'Leave a voice call',
        description: 'Removes the caller from the channel\'s voice room (no-op when not joined), publishes the '
            .'updated participant list over Mercure and re-evaluates presence. Returns `204`.',
    ),
)]
#[Post(
    uriTemplate: '/communities/{community}/channels/{channel}/mark-read',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'channel' => new Link(fromClass: Channel::class, identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    input: false,
    output: false,
    provider: ChannelProvider::class,
    processor: MarkChannelReadProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Mark a channel as read',
        description: 'Any user who may view the channel. Upserts the caller\'s per-channel read state to the '
            .'current time, clearing the unread indicator. Returns `204`.',
    ),
)]
#[Post(
    uriTemplate: '/communities/{community}/channels/{channel}/archive',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'channel' => new Link(fromClass: Channel::class, identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    input: false,
    output: false,
    provider: ChannelProvider::class,
    processor: ArchiveChannelProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Archive a channel',
        description: 'Requires community admin. Freezes a text channel: no new messages, replies, reactions, edits, '
            .'deletions or pins for anyone. History stays readable and searchable. Returns `204`. `422` for audio '
            .'channels, the welcome channel, or an already-archived channel.',
    ),
)]
#[Post(
    uriTemplate: '/communities/{community}/channels/{channel}/unarchive',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'channel' => new Link(fromClass: Channel::class, identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    input: false,
    output: false,
    provider: ChannelProvider::class,
    processor: UnarchiveChannelProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Unarchive a channel',
        description: 'Requires community admin. Restores an archived channel to its original section with full '
            .'write access. Returns `204`. `422` when the channel is not archived.',
    ),
)]
#[Put(
    uriTemplate: '/communities/{community}/channels/{channel}/notification-preference',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'channel' => new Link(fromClass: Channel::class, identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    input: SetChannelLevelDto::class,
    output: SetChannelLevelDto::class,
    provider: ChannelProvider::class,
    processor: SetChannelNotificationLevelProcessor::class,
    normalizationContext: ['groups' => ['channel_notification_level:read']],
    denormalizationContext: ['groups' => ['channel_notification_level:write']],
    formats: ['jsonld' => ['application/ld+json'], 'json' => ['application/json']],
    extraProperties: ['standard_put' => false, 'scopeResource' => 'notifications'],
    openapi: new Model\Operation(
        summary: 'Set channel notification level',
        description: 'Any user who may view the channel. Sets the caller\'s per-channel notification level to '
            .'`all`, `mentions` or `none`; `null` resets to the default (`mentions`). Echoes the level back.',
    ),
)]
#[Put(
    uriTemplate: '/communities/{community}/channels/{channel}/pin-state',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'channel' => new Link(fromClass: Channel::class, identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    input: SetChannelPinStateDto::class,
    output: SetChannelPinStateDto::class,
    provider: ChannelProvider::class,
    processor: SetChannelPinStateProcessor::class,
    normalizationContext: ['groups' => ['channel_pin_state:read']],
    denormalizationContext: ['groups' => ['channel_pin_state:write']],
    formats: ['jsonld' => ['application/ld+json'], 'json' => ['application/json']],
    extraProperties: ['standard_put' => false, 'scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Set channel pin state',
        description: 'Any user who may view the channel. Sets the caller\'s per-channel sidebar pin state to '
            .'`favorite` or `hidden`; `null` clears it. Echoes the state back.',
    ),
)]
#[Post(
    uriTemplate: '/communities/{community}/channels/{channel}/typing',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'channel' => new Link(fromClass: Channel::class, identifiers: ['identifier']),
    ],
    security: "is_granted('CHANNEL_POST', object)",
    input: false,
    output: false,
    provider: ChannelProvider::class,
    processor: PublishChannelTypingProcessor::class,
    extraProperties: ['scopeResource' => 'messages'],
    openapi: new Model\Operation(
        summary: 'Send a typing ping',
        description: 'Any user allowed to post in the channel. Publishes an ephemeral "user is typing" event to '
            .'the channel\'s Mercure topic; nothing is persisted. Returns `204`.',
    ),
)]
class Channel
{
    use BlameableEntity;
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['community:read', 'channel:read', 'section:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Gedmo\Slug(fields: ['name'], updatable: false)]
    #[Groups(['community:read', 'channel:read', 'section:read'])]
    private ?string $identifier = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['community:read', 'channel:read', 'channel:create', 'channel:update', 'section:read'])]
    private ?string $description = null;

    #[ORM\ManyToOne(inversedBy: 'channels')]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['community:read', 'channel:read', 'channel:create', 'channel:update'])]
    private ?ChannelSection $section = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['channel:read', 'community:read', 'section:read'])]
    private int $position = 0;

    #[ORM\ManyToOne(inversedBy: 'channels')]
    #[ORM\JoinColumn(nullable: true)]
    #[Assert\NotBlank]
    #[Groups(['channel:create'])]
    private ?Community $community = null;

    #[ORM\Column(length: 255)]
    #[Groups(['community:read', 'channel:read', 'channel:update', 'section:read'])]
    #[Assert\Length(max: 255)]
    #[Assert\NotBlank]
    private ?string $name = null;

    // See Community::$private for the serializer prefix constraint.
    #[ORM\Column(name: 'is_private', options: ['default' => false])]
    #[Groups(['community:read', 'channel:read', 'channel:create', 'channel:update'])]
    #[SerializedName('isPrivate')]
    private bool $private = false;

    #[ORM\Column(nullable: true)]
    #[Groups(['community:read', 'channel:read'])]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column(name: 'is_readonly', options: ['default' => false])]
    #[Groups(['community:read', 'channel:read', 'channel:create', 'channel:update'])]
    #[SerializedName('isReadonly')]
    private bool $readonly = false;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['community:read', 'channel:read', 'channel:create', 'channel:update'])]
    private bool $areReadonlyRepliesAllowed = false;

    #[ORM\Column(length: 20, enumType: ChannelType::class)]
    #[Groups(['community:read', 'channel:read', 'channel:create', 'channel:update'])]
    private ChannelType $type = ChannelType::Text;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['community:read', 'channel:read', 'channel:create', 'channel:update'])]
    private bool $allowAttachments = true;

    /**
     * @var Collection<int, MessagePage>
     */
    #[ORM\OneToMany(targetEntity: MessagePage::class, mappedBy: 'channel', orphanRemoval: true, fetch: 'EXTRA_LAZY')]
    #[ORM\OrderBy(['pageNumber' => 'ASC'])]
    private Collection $pages;

    public function __construct()
    {
        $this->pages = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): static
    {
        $this->identifier = $identifier;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getSection(): ?ChannelSection
    {
        return $this->section;
    }

    public function setSection(?ChannelSection $section): static
    {
        $this->section = $section;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getCommunity(): ?Community
    {
        return $this->community;
    }

    public function setCommunity(?Community $community): static
    {
        $this->community = $community;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function isPrivate(): bool
    {
        return $this->private;
    }

    public function setPrivate(?bool $private): static
    {
        $this->private = $private ?? false;

        return $this;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function setArchivedAt(?\DateTimeImmutable $archivedAt): static
    {
        $this->archivedAt = $archivedAt;

        return $this;
    }

    public function isArchived(): bool
    {
        return null !== $this->archivedAt;
    }

    public function isReadonly(): bool
    {
        return $this->readonly;
    }

    public function setReadonly(?bool $readonly): static
    {
        $this->readonly = $readonly ?? false;

        return $this;
    }

    public function getAreReadonlyRepliesAllowed(): bool
    {
        return $this->areReadonlyRepliesAllowed;
    }

    public function setAreReadonlyRepliesAllowed(?bool $areReadonlyRepliesAllowed): static
    {
        $this->areReadonlyRepliesAllowed = $areReadonlyRepliesAllowed ?? false;

        return $this;
    }

    public function getType(): ChannelType
    {
        return $this->type;
    }

    public function setType(ChannelType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getAllowAttachments(): bool
    {
        return $this->allowAttachments;
    }

    public function setAllowAttachments(bool $allowAttachments): static
    {
        $this->allowAttachments = $allowAttachments;

        return $this;
    }

    /**
     * @return Collection<int, MessagePage>
     */
    public function getPages(): Collection
    {
        return $this->pages;
    }

    public function addPage(MessagePage $page): static
    {
        if (!$this->pages->contains($page)) {
            $this->pages->add($page);
            $page->setChannel($this);
        }

        return $this;
    }

    public function getCommunityIri(): string
    {
        return '/api/communities/'.($this->community?->getIdentifier() ?? '');
    }

    public function getTopicIri(): string
    {
        return $this->getCommunityIri().'/channels/'.($this->getIdentifier() ?? '');
    }
}
