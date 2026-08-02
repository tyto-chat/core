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
use ApiPlatform\OpenApi\Model;
use App\Dto\UserGroup\CreateUserGroupDto;
use App\Dto\UserGroup\TransferGroupOwnershipDto;
use App\Dto\UserGroup\UpdateUserGroupDto;
use App\Repository\UserGroupRepository;
use App\State\UserGroup\Processor\CreateUserGroupProcessor;
use App\State\UserGroup\Processor\DeleteUserGroupProcessor;
use App\State\UserGroup\Processor\TransferGroupOwnershipProcessor;
use App\State\UserGroup\Processor\UpdateUserGroupProcessor;
use App\State\UserGroup\Provider\UserGroupProvider;
use App\State\UserGroup\Provider\UserGroupsProvider;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserGroupRepository::class)]
#[ORM\UniqueConstraint(fields: ['community', 'identifier'])]
#[ApiResource(
    description: 'A named group of community members that can carry channel-role grants.',
    normalizationContext: ['groups' => ['user_group:read']],
    denormalizationContext: ['groups' => ['user_group:write']],
)]
#[GetCollection(
    uriTemplate: '/communities/{community}/groups',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: UserGroupsProvider::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'List community groups',
        description: 'Requires authentication. Returns the community\'s groups visible to the caller: hidden '
            .'groups are only included for their members and community admins.',
    ),
)]
#[Get(
    uriTemplate: '/communities/{community}/groups/{identifier}',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'identifier' => new Link(fromClass: UserGroup::class, identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: UserGroupProvider::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Get a group',
        description: 'Requires authentication. Returns a single group by identifier. Non-hidden groups are '
            .'visible to any community member; hidden groups only to their members, the owner, community admins '
            .'and global admins — others receive `404` (existence is not leaked).',
    ),
)]
#[Post(
    uriTemplate: '/communities/{community}/groups',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    read: false,
    input: CreateUserGroupDto::class,
    processor: CreateUserGroupProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Create a group',
        description: 'Requires community admin. Creates a group with a name, optional icon and `#rrggbb` color, '
            .'and an `isHidden` flag; the identifier is slugged from the name.',
    ),
)]
#[Patch(
    uriTemplate: '/communities/{community}/groups/{identifier}',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'identifier' => new Link(fromClass: UserGroup::class, identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    input: UpdateUserGroupDto::class,
    provider: UserGroupProvider::class,
    processor: UpdateUserGroupProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Update a group',
        description: 'Requires community admin. Updates the group\'s name, icon, color, hidden flag and owner. '
            .'Returns `422` when the given `ownerId` does not exist or is not a current member of the group.',
    ),
)]
#[Delete(
    uriTemplate: '/communities/{community}/groups/{identifier}',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'identifier' => new Link(fromClass: UserGroup::class, identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: UserGroupProvider::class,
    processor: DeleteUserGroupProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Delete a group',
        description: 'Requires community admin. Deletes the group together with its memberships and channel '
            .'permission grants.',
    ),
)]
#[Post(
    uriTemplate: '/communities/{community}/groups/{identifier}/transfer-ownership',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'identifier' => new Link(fromClass: UserGroup::class, identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    input: TransferGroupOwnershipDto::class,
    provider: UserGroupProvider::class,
    processor: TransferGroupOwnershipProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Transfer group ownership',
        description: 'Requires community admin, the current group owner, or global admin. Makes the given user '
            .'the group\'s owner and sends them a `group_ownership_transferred` notification. Returns `422` when '
            .'the user is already the owner or is not a member of the group.',
    ),
)]
class UserGroup
{
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[ApiProperty(identifier: false)]
    #[Groups(['user_group:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Community $community;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $owner = null;

    #[ORM\Column(length: 100)]
    #[ApiProperty(identifier: true)]
    #[Groups(['user_group:read'])]
    #[Gedmo\Slug(fields: ['name'], updatable: false, unique_base: 'community')]
    private ?string $identifier = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Groups(['user_group:read'])]
    private string $name = '';

    #[ORM\Column(length: 10, nullable: true)]
    #[Groups(['user_group:read'])]
    private ?string $icon = null;

    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\AtLeastOneOf([
        new Assert\IsNull(),
        new Assert\Regex(
            pattern: '/^#[0-9a-f]{6}$/',
            message: 'color must be null or a lowercase hex color in #rrggbb format.',
        ),
    ])]
    #[Groups(['user_group:read'])]
    private ?string $color = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['user_group:read'])]
    private bool $isHidden = false;

    /** @var Collection<int, UserGroupMember> */
    #[ORM\OneToMany(targetEntity: UserGroupMember::class, mappedBy: 'userGroup', orphanRemoval: true)]
    private Collection $members;

    /** @var Collection<int, GroupChannelPermission> */
    #[ORM\OneToMany(targetEntity: GroupChannelPermission::class, mappedBy: 'userGroup', orphanRemoval: true)]
    private Collection $channelPermissions;

    public function __construct()
    {
        $this->members = new ArrayCollection();
        $this->channelPermissions = new ArrayCollection();
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

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    #[Groups(['user_group:read'])]
    public function getOwnerId(): ?int
    {
        return $this->owner?->getId();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getIsHidden(): bool
    {
        return $this->isHidden;
    }

    public function setIsHidden(bool $isHidden): static
    {
        $this->isHidden = $isHidden;

        return $this;
    }

    /** @return Collection<int, UserGroupMember> */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    /** @return Collection<int, GroupChannelPermission> */
    public function getChannelPermissions(): Collection
    {
        return $this->channelPermissions;
    }

    #[Groups(['user_group:read'])]
    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    #[Groups(['user_group:read'])]
    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }
}
