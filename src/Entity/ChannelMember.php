<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model;
use App\Dto\Channel\UpdateChannelMemberRoleDto;
use App\Enum\Channel\ChannelRole;
use App\Repository\ChannelMemberRepository;
use App\State\Channel\Processor\UpdateChannelMemberRoleProcessor;
use App\State\Channel\Provider\ChannelMembersProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: ChannelMemberRepository::class)]
#[ORM\UniqueConstraint(fields: ['user', 'channel'])]
#[ApiResource(
    description: 'A user\'s explicit membership in a channel, with a per-channel role.',
    normalizationContext: ['groups' => ['channel_member:read']],
)]
#[GetCollection(
    uriTemplate: '/communities/{community}/channels/{channel}/members',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, identifiers: ['identifier']),
        'channel' => new Link(fromClass: Channel::class, identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: ChannelMembersProvider::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'List channel members',
        description: 'Requires channel membership (direct or via a group grant), community moderator/admin, or '
            .'global admin. Returns the channel\'s explicit member rows with their `member`/`moderator` roles.',
    ),
)]
#[Patch(
    uriTemplate: '/communities/{community}/channels/{channel}/members/{userId}/role',
    uriVariables: ['community', 'channel', 'userId'],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['groups' => ['channel_member:read']],
    input: UpdateChannelMemberRoleDto::class,
    read: false,
    processor: UpdateChannelMemberRoleProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Change a channel member\'s role',
        description: 'Requires community admin. Sets the member\'s channel role to `member` or `moderator`; a '
            .'promotion to moderator sends the user a `channel_moderator` notification. Returns `404` when the '
            .'user is not a member of the channel.',
    ),
)]
class ChannelMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['channel_member:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Channel $channel;

    #[ORM\Column(length: 20, enumType: ChannelRole::class)]
    #[Groups(['channel_member:read'])]
    private ChannelRole $role = ChannelRole::Member;

    #[ORM\Column]
    #[Groups(['channel_member:read'])]
    private \DateTimeImmutable $addedAt;

    public function __construct()
    {
        $this->addedAt = new \DateTimeImmutable();
    }

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

    public function getChannel(): Channel
    {
        return $this->channel;
    }

    public function setChannel(Channel $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function getRole(): ChannelRole
    {
        return $this->role;
    }

    public function setRole(ChannelRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    #[Groups(['channel_member:read'])]
    public function getUserId(): ?int
    {
        return $this->user->getId();
    }

    #[Groups(['channel_member:read'])]
    public function getProfile(): ?Profile
    {
        return $this->user->getProfile();
    }
}
