<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model;
use App\Dto\ChannelSection\ReorderSectionsDto;
use App\Dto\Community\CommunityMembershipDto;
use App\Dto\Community\CreateCommunityDto;
use App\Dto\Community\MyCommunityMembershipDto;
use App\Dto\Community\UpdateCommunityDto;
use App\Dto\Notification\SetCommunityMuteDto;
use App\Enum\Community\BroadcastMentionRole;
use App\Repository\CommunityRepository;
use App\State\Community\Processor\CreateCommunityProcessor;
use App\State\Community\Processor\DeleteCommunityProcessor;
use App\State\Community\Processor\JoinCommunityProcessor;
use App\State\Community\Processor\LeaveCommunityProcessor;
use App\State\Community\Processor\MarkCommunityNotificationsReadProcessor;
use App\State\Community\Processor\MarkCommunityReadProcessor;
use App\State\Community\Processor\ReorderSectionsProcessor;
use App\State\Community\Processor\SetCommunityMuteProcessor;
use App\State\Community\Processor\UpdateCommunityProcessor;
use App\State\Community\Provider\ChannelUnreadProvider;
use App\State\Community\Provider\CommunityMembershipProvider;
use App\State\Community\Provider\CommunityProvider;
use App\State\Community\Provider\MyCommunityMembershipsProvider;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Blameable\Traits\BlameableEntity;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CommunityRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_COMMUNITY_IDENTIFIER', fields: ['identifier'])]
#[UniqueEntity(fields: ['identifier'], message: 'Identifier for this Community name is already in use. Use a different one.')]
#[ApiResource(
    description: 'A chat community: the top-level grouping of channels, sections, members and settings, addressed by its slug identifier.',
    normalizationContext: ['groups' => ['community:read']],
    denormalizationContext: ['groups' => ['community:create', 'community:update']],
)]
#[Delete(
    uriTemplate: '/communities/{identifier}',
    uriVariables: ['identifier' => new Link(fromClass: Community::class, identifiers: ['identifier'])],
    security: "is_granted('ROLE_ADMIN')",
    processor: DeleteCommunityProcessor::class,
    extraProperties: ['scope' => 'admin'],
    openapi: new Model\Operation(
        summary: 'Delete a community',
        description: 'Global admin only. Permanently removes the community and everything it owns, '
            .'then purges the cached community detail.',
    ),
)]
#[Post(
    uriTemplate: '/communities',
    security: "is_granted('ROLE_ADMIN')",
    input: CreateCommunityDto::class,
    processor: CreateCommunityProcessor::class,
    extraProperties: ['scope' => 'admin'],
    openapi: new Model\Operation(
        summary: 'Create a community',
        description: 'Global admin only. Creates a new community; its URL `identifier` is slugged from the name.',
    ),
)]
#[Get(
    uriTemplate: '/communities/{identifier}',
    uriVariables: ['identifier' => new Link(fromClass: Community::class, identifiers: ['identifier'])],
    provider: CommunityProvider::class,
    extraProperties: ['tyto_http_cache' => true, 'scopeResource' => 'communities'],
    cacheHeaders: ['vary' => ['Content-Type', 'Origin']],
    openapi: new Model\Operation(
        summary: 'Get a community',
        description: 'Public communities are visible to anyone, including anonymous callers; '
            .'private communities require membership or global admin (`404` otherwise — existence is not revealed). '
            .'Served through the shared HTTP cache.',
    ),
)]
#[GetCollection(
    provider: CommunityProvider::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'List communities',
        description: 'Anonymous callers see public communities only; authenticated users see public '
            .'communities plus the ones they joined; global admins see all.',
    ),
)]
#[Patch(
    uriTemplate: '/communities/{identifier}',
    uriVariables: ['identifier' => new Link(fromClass: Community::class, identifiers: ['identifier'])],
    security: "is_granted('ROLE_USER')",
    input: UpdateCommunityDto::class,
    processor: UpdateCommunityProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Update a community',
        description: 'Requires community admin. Updates profile and settings fields (name, description, '
            .'accent color, locale, broadcast-mention minimum role, welcome channel). '
            .'An invalid `welcomeChannelIdentifier` (other community, non-text or private channel) '
            .'returns `422`. Publishes a `community.structure` Mercure event.',
    ),
)]
#[Post(
    uriTemplate: '/communities/{identifier}/members',
    uriVariables: ['identifier' => new Link(fromClass: Community::class, identifiers: ['identifier'])],
    status: 204,
    security: "is_granted('ROLE_USER')",
    input: false,
    processor: JoinCommunityProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Join a community',
        description: 'Any authenticated user. Private communities reject self-join with `403` '
            .'(invite or admin-add required; global admins bypass), banned users get `403`, '
            .'joining twice returns `409`. Auto-pins the community for the caller, may post a bot '
            .'welcome message, and publishes a `community.structure` Mercure event.',
    ),
)]
#[Delete(
    uriTemplate: '/communities/{identifier}/members',
    uriVariables: ['identifier' => new Link(fromClass: Community::class, identifiers: ['identifier'])],
    security: "is_granted('ROLE_USER')",
    processor: LeaveCommunityProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Leave a community',
        description: 'Removes the caller from a community they joined. Returns `409` when the caller '
            .'is not a member and `422` when they are the last community admin. Unpins the community, '
            .'publishes a `community.structure` Mercure event and disconnects the caller from any '
            .'voice channel in the community.',
    ),
)]
#[Get(
    uriTemplate: '/communities/{identifier}/unread-channels',
    uriVariables: ['identifier' => new Link(fromClass: Community::class, identifiers: ['identifier'])],
    security: "is_granted('ROLE_USER')",
    output: \App\Dto\Channel\UnreadChannelsDto::class,
    provider: ChannelUnreadProvider::class,
    normalizationContext: ['groups' => ['channel_unread:read']],
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'List unread channels in a community',
        description: 'Returns the identifiers of channels the caller has unread messages in. '
            .'Any authenticated user who may view the community.',
    ),
)]
#[Get(
    uriTemplate: '/communities/{identifier}/membership',
    uriVariables: ['identifier' => new Link(fromClass: Community::class, identifiers: ['identifier'])],
    security: "is_granted('ROLE_USER')",
    output: CommunityMembershipDto::class,
    provider: CommunityMembershipProvider::class,
    normalizationContext: ['groups' => ['community_membership:read']],
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Get my membership in a community',
        description: 'Returns the caller\'s own standing: real membership role (no global-admin bypass), '
            .'whether a membership row exists, and merged per-channel roles (direct plus group-derived). '
            .'Private communities the caller may not view return `404` to hide their existence.',
    ),
)]
#[GetCollection(
    uriTemplate: '/me/community-memberships',
    security: "is_granted('ROLE_USER')",
    output: MyCommunityMembershipDto::class,
    provider: MyCommunityMembershipsProvider::class,
    normalizationContext: ['groups' => ['community_membership:read']],
    paginationEnabled: false,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'List my community memberships',
        description: 'Returns every community the caller holds a real membership row in, with the '
            .'community id, identifier and role. Unpaginated.',
    ),
)]
#[Post(
    uriTemplate: '/communities/{identifier}/mark-all-read',
    uriVariables: ['identifier' => new Link(fromClass: Community::class, identifiers: ['identifier'])],
    status: 204,
    security: "is_granted('ROLE_USER')",
    input: false,
    output: false,
    processor: MarkCommunityReadProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Mark a whole community as read',
        description: 'Any authenticated user who may view the community. Marks every channel read and '
            .'every notification scoped to the community read for the caller. Returns `204`.',
    ),
)]
#[Post(
    uriTemplate: '/communities/{identifier}/notifications/mark-all-read',
    uriVariables: ['identifier' => new Link(fromClass: Community::class, identifiers: ['identifier'])],
    status: 204,
    security: "is_granted('ROLE_USER')",
    input: false,
    output: false,
    processor: MarkCommunityNotificationsReadProcessor::class,
    extraProperties: ['scopeResource' => 'notifications'],
    openapi: new Model\Operation(
        summary: 'Mark community notifications as read',
        description: 'Any authenticated user who may view the community. Marks the caller\'s '
            .'notifications scoped to this community read while leaving channel read state '
            .'untouched. Returns `204`.',
    ),
)]
#[Put(
    uriTemplate: '/communities/{identifier}/notification-preference',
    uriVariables: ['identifier' => new Link(fromClass: Community::class, identifiers: ['identifier'])],
    security: "is_granted('ROLE_USER')",
    input: SetCommunityMuteDto::class,
    output: SetCommunityMuteDto::class,
    processor: SetCommunityMuteProcessor::class,
    normalizationContext: ['groups' => ['community_mute:read']],
    denormalizationContext: ['groups' => ['community_mute:write']],
    formats: ['jsonld' => ['application/ld+json'], 'json' => ['application/json']],
    extraProperties: ['standard_put' => false, 'scopeResource' => 'notifications'],
    openapi: new Model\Operation(
        summary: 'Mute or unmute community notifications',
        description: 'Sets the community-wide notification mute for the caller and echoes `muted` back. '
            .'Requires a real membership row in the community (`409` otherwise). While muted, every '
            .'notification scoped to this community is dropped server-side.',
    ),
)]
#[Put(
    uriTemplate: '/communities/{identifier}/sections/order',
    uriVariables: ['identifier' => new Link(fromClass: Community::class, identifiers: ['identifier'])],
    security: "is_granted('ROLE_USER')",
    input: ReorderSectionsDto::class,
    output: false,
    status: 204,
    read: false,
    processor: ReorderSectionsProcessor::class,
    denormalizationContext: ['groups' => ['section_reorder:write']],
    formats: ['jsonld' => ['application/ld+json'], 'json' => ['application/json']],
    extraProperties: ['standard_put' => false, 'scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Reorder channel sections',
        description: 'Requires community admin. The payload must list exactly the section ids of the '
            .'community, each once, in the desired order; anything else returns `422`. Returns `204` '
            .'and publishes a `community.structure` Mercure event.',
    ),
)]
class Community
{
    use BlameableEntity;
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[ApiProperty(identifier: false)]
    #[Groups(['community:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[ApiProperty(identifier: true)]
    #[Groups(['community:read'])]
    #[Gedmo\Slug(fields: ['name'], updatable: false)]
    private ?string $identifier = null;

    #[ORM\Column(length: 255)]
    #[Groups(['community:create', 'community:update', 'community:read'])]
    private ?string $name = null;

    // Serializer only sees get/is/has/can accessors matching the prop name — $isPrivate + isPrivate() drops off the wire.
    #[ORM\Column(name: 'is_private', options: ['default' => false])]
    #[Groups(['community:create', 'community:update', 'community:read'])]
    #[SerializedName('isPrivate')]
    private bool $private = false;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['community:create', 'community:update', 'community:read'])]
    private ?string $hostname = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['community:create', 'community:update', 'community:read'])]
    private ?string $description = null;

    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\AtLeastOneOf([
        new Assert\IsNull(),
        new Assert\Regex(
            pattern: '/^#[0-9a-f]{6}$/',
            message: 'accentColor must be null or a lowercase hex color in #rrggbb format.',
        ),
    ])]
    #[Groups(['community:create', 'community:update', 'community:read'])]
    private ?string $accentColor = null;

    #[ORM\Column(length: 20, enumType: BroadcastMentionRole::class, options: ['default' => 'member'])]
    #[Groups(['community:update', 'community:read'])]
    private BroadcastMentionRole $broadcastMentionMinRole = BroadcastMentionRole::Member;

    #[ORM\Column(length: 12, options: ['default' => 'en'])]
    #[Groups(['community:create', 'community:update', 'community:read'])]
    private string $locale = 'en';

    #[ORM\ManyToOne(targetEntity: Channel::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['community:read'])]
    private ?Channel $welcomeChannel = null;

    #[ORM\OneToOne(cascade: ['remove'])]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['community:read'])]
    private ?MediaObject $logo = null;

    /**
     * @var Collection<int, Channel>
     */
    #[ORM\OneToMany(targetEntity: Channel::class, mappedBy: 'community', orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Groups(['community:create', 'community:update', 'community:read'])]
    private Collection $channels;

    /**
     * @var Collection<int, ChannelSection>
     */
    #[ORM\OneToMany(targetEntity: ChannelSection::class, mappedBy: 'community', orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Groups(['community:create', 'community:update', 'community:read'])]
    private Collection $channelSections;

    /**
     * @var Collection<int, CommunityEmoji>
     */
    #[ORM\OneToMany(targetEntity: CommunityEmoji::class, mappedBy: 'community', orphanRemoval: true)]
    private Collection $emojis;

    /** @var User|null */
    #[Gedmo\Blameable(on: 'create')]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['message:read'])]
    protected $createdBy;

    /** @var User|null */
    #[Gedmo\Blameable(on: 'update')]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    protected $updatedBy;

    public function __construct()
    {
        $this->channels = new ArrayCollection();
        $this->channelSections = new ArrayCollection();
        $this->emojis = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): Community
    {
        $this->identifier = $identifier;

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

    public function setPrivate(bool $private): static
    {
        $this->private = $private;

        return $this;
    }

    public function getHostname(): ?string
    {
        return $this->hostname;
    }

    public function setHostname(?string $hostname): static
    {
        $this->hostname = $hostname;

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

    public function getLogo(): ?MediaObject
    {
        return $this->logo;
    }

    public function setLogo(?MediaObject $logo): static
    {
        $this->logo = $logo;

        return $this;
    }

    /**
     * @return Collection<int, Channel>
     */
    public function getChannels(): Collection
    {
        return $this->channels;
    }

    public function addChannel(Channel $channel): static
    {
        if (!$this->channels->contains($channel)) {
            $this->channels->add($channel);
            $channel->setCommunity($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, ChannelSection>
     */
    public function getChannelSections(): Collection
    {
        return $this->channelSections;
    }

    public function addChannelSection(ChannelSection $channelSection): static
    {
        if (!$this->channelSections->contains($channelSection)) {
            $this->channelSections->add($channelSection);
            $channelSection->setCommunity($this);
        }

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

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy): static
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }

    public function getAccentColor(): ?string
    {
        return $this->accentColor;
    }

    public function setAccentColor(?string $accentColor): static
    {
        $this->accentColor = $accentColor;

        return $this;
    }

    public function getBroadcastMentionMinRole(): BroadcastMentionRole
    {
        return $this->broadcastMentionMinRole;
    }

    public function setBroadcastMentionMinRole(BroadcastMentionRole $role): static
    {
        $this->broadcastMentionMinRole = $role;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getWelcomeChannel(): ?Channel
    {
        return $this->welcomeChannel;
    }

    public function setWelcomeChannel(?Channel $channel): static
    {
        $this->welcomeChannel = $channel;

        return $this;
    }

    /**
     * @return Collection<int, CommunityEmoji>
     */
    public function getEmojis(): Collection
    {
        return $this->emojis;
    }
}
