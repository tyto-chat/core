<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model;
use App\Dto\UserGroup\SetGroupChannelPermissionDto;
use App\Enum\Channel\ChannelRole;
use App\Repository\GroupChannelPermissionRepository;
use App\State\UserGroup\Processor\RemoveGroupChannelPermissionProcessor;
use App\State\UserGroup\Processor\SetGroupChannelPermissionProcessor;
use App\State\UserGroup\Provider\GroupChannelPermissionsProvider;
use App\State\UserGroup\Provider\UserGroupProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: GroupChannelPermissionRepository::class)]
#[ORM\UniqueConstraint(fields: ['userGroup', 'channel'])]
#[ApiResource(
    description: 'A channel-role grant a user group confers on its members.',
    normalizationContext: ['groups' => ['group_channel_permission:read']],
)]
#[GetCollection(
    uriTemplate: '/communities/{community}/groups/{identifier}/channel-permissions',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, identifiers: ['identifier']),
        'identifier' => new Link(fromClass: UserGroup::class, identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: GroupChannelPermissionsProvider::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'List a group\'s channel permissions',
        description: 'Requires community admin, the group\'s owner, or global admin. Returns the channel-role '
            .'grants attached to the group. Hidden groups return `404` for users who cannot view them.',
    ),
)]
#[Put(
    uriTemplate: '/communities/{community}/groups/{identifier}/channel-permissions/{channelIdentifier}',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, fromProperty: 'community', identifiers: ['identifier']),
        'identifier' => new Link(fromClass: UserGroup::class, fromProperty: 'userGroup', identifiers: ['identifier']),
        'channelIdentifier' => new Link(fromClass: Channel::class, fromProperty: 'channel', identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    input: SetGroupChannelPermissionDto::class,
    provider: UserGroupProvider::class,
    processor: SetGroupChannelPermissionProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Set a group\'s permission on a channel',
        description: 'Requires community admin. Creates or updates the group\'s grant on the channel with role '
            .'`member` or `moderator`, publishes a `community.structure` Mercure update and, for private channels, '
            .'emits `channel.access.granted` user events to group members. Returns `422` for an invalid role or a '
            .'channel outside the group\'s community.',
    ),
)]
#[Delete(
    uriTemplate: '/communities/{community}/groups/{identifier}/channel-permissions/{channelIdentifier}',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, fromProperty: 'community', identifiers: ['identifier']),
        'identifier' => new Link(fromClass: UserGroup::class, fromProperty: 'userGroup', identifiers: ['identifier']),
        'channelIdentifier' => new Link(fromClass: Channel::class, fromProperty: 'channel', identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: UserGroupProvider::class,
    processor: RemoveGroupChannelPermissionProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Remove a group\'s permission on a channel',
        description: 'Requires community admin. Deletes the group\'s grant on the channel (no-op when none '
            .'exists), publishes a `community.structure` Mercure update and, for private channels, emits '
            .'`channel.access.revoked` user events to group members who lose access.',
    ),
)]
class GroupChannelPermission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['group_channel_permission:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'channelPermissions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private UserGroup $userGroup;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Channel $channel;

    #[ORM\Column(length: 20, enumType: ChannelRole::class)]
    #[Groups(['group_channel_permission:read'])]
    private ChannelRole $role = ChannelRole::Member;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserGroup(): UserGroup
    {
        return $this->userGroup;
    }

    public function setUserGroup(UserGroup $userGroup): static
    {
        $this->userGroup = $userGroup;

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

    #[Groups(['group_channel_permission:read'])]
    public function getChannelId(): ?int
    {
        return $this->channel->getId();
    }

    public function getCommunity(): Community
    {
        return $this->userGroup->getCommunity();
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
}
