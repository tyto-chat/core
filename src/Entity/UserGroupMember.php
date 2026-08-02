<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\UserGroup\AddGroupMemberDto;
use App\Repository\UserGroupMemberRepository;
use App\State\UserGroup\Processor\AddGroupMemberProcessor;
use App\State\UserGroup\Processor\RemoveGroupMemberProcessor;
use App\State\UserGroup\Provider\UserGroupMembersProvider;
use App\State\UserGroup\Provider\UserGroupProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: UserGroupMemberRepository::class)]
#[ORM\UniqueConstraint(fields: ['user', 'userGroup'])]
#[ApiResource(
    description: 'A user\'s membership in a user group.',
    normalizationContext: ['groups' => ['user_group_member:read']],
)]
#[GetCollection(
    uriTemplate: '/communities/{community}/groups/{identifier}/members',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, identifiers: ['identifier']),
        'identifier' => new Link(fromClass: UserGroup::class, toProperty: 'userGroup', identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: UserGroupMembersProvider::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'List group members',
        description: 'Requires group membership, the group\'s owner, community admin, or global admin. Returns '
            .'the group\'s member rows. Hidden groups return `404` for users who cannot view them.',
    ),
)]
#[Post(
    uriTemplate: '/communities/{community}/groups/{identifier}/members',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, identifiers: ['identifier']),
        'identifier' => new Link(fromClass: UserGroup::class, toProperty: 'userGroup', identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    input: AddGroupMemberDto::class,
    provider: UserGroupProvider::class,
    processor: AddGroupMemberProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Add a group member',
        description: 'Requires community admin, the group\'s owner, or global admin. Adds a community member to '
            .'the group, sends them a `group_added` notification, publishes a `community.structure` Mercure '
            .'update and emits `channel.access.granted` user events for private channels the group unlocks. '
            .'Returns `422` when the user is not a community member or is already in the group.',
    ),
)]
#[Delete(
    uriTemplate: '/communities/{community}/groups/{identifier}/members/{userId}',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, identifiers: ['identifier']),
        'identifier' => new Link(fromClass: UserGroup::class, toProperty: 'userGroup', identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: UserGroupProvider::class,
    processor: RemoveGroupMemberProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Remove a group member',
        description: 'Members may leave on their own; removing someone else requires community admin, the '
            .'group\'s owner, or global admin. Removed users get a `group_removed` notification (not on '
            .'self-leave); a `community.structure` Mercure update and `channel.access.revoked` events are '
            .'published for private channels they lose. Returns `422` when the owner tries to leave without '
            .'transferring ownership and `404` when the user is not a member.',
    ),
)]
class UserGroupMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['user_group_member:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private UserGroup $userGroup;

    #[ORM\Column]
    #[Groups(['user_group_member:read'])]
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

    public function getUserGroup(): UserGroup
    {
        return $this->userGroup;
    }

    public function setUserGroup(UserGroup $userGroup): static
    {
        $this->userGroup = $userGroup;

        return $this;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }

    #[Groups(['user_group_member:read'])]
    public function getUserId(): ?int
    {
        return $this->user->getId();
    }
}
