<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\CommunityMember\AddCommunityMemberDto;
use App\Dto\CommunityMember\UpdateCommunityMemberRoleDto;
use App\Enum\Community\CommunityRole;
use App\Repository\CommunityMemberRepository;
use App\State\CommunityMember\Processor\AddCommunityMemberProcessor;
use App\State\CommunityMember\Processor\UpdateCommunityMemberRoleProcessor;
use App\State\CommunityMember\Provider\CommunityMembersProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: CommunityMemberRepository::class)]
#[ORM\UniqueConstraint(fields: ['user', 'community'])]
#[ApiResource(
    description: 'A user\'s membership in a community, carrying their community-level role and join date.',
    normalizationContext: ['groups' => ['member:read']],
)]
#[GetCollection(
    uriTemplate: '/communities/{community}/members',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: CommunityMembersProvider::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'List community members',
        description: 'Any authenticated user who may view the community (private communities require '
            .'membership or global admin). Bot accounts are excluded; global admins always appear, '
            .'synthesized as transient rows when they hold no real membership.',
    ),
)]
#[Post(
    uriTemplate: '/communities/{community}/members/add',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, identifiers: ['identifier']),
    ],
    security: "is_granted('ROLE_USER')",
    read: false,
    input: AddCommunityMemberDto::class,
    processor: AddCommunityMemberProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Add a member to a community',
        description: 'Requires community admin. Adds the user with an optional role; idempotent — '
            .'returns the existing membership when the user already belongs. Banned users are '
            .'rejected with `403`. Auto-pins the community for the user, may post a bot welcome '
            .'message, and publishes a `community.structure` Mercure event.',
    ),
)]
#[Patch(
    uriTemplate: '/communities/{community}/members/{memberId}',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, identifiers: ['identifier']),
        'memberId' => new Link(fromClass: CommunityMember::class, identifiers: ['id']),
    ],
    security: "is_granted('ROLE_USER')",
    read: false,
    input: UpdateCommunityMemberRoleDto::class,
    processor: UpdateCommunityMemberRoleProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Change a community member\'s role',
        description: 'Requires community admin. Demoting the last community admin returns `422`; an '
            .'unknown member id returns `409`. Publishes a `community.structure` Mercure event and a '
            .'`role.changed` event to the affected user.',
    ),
)]
class CommunityMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['member:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Community $community;

    #[ORM\Column(length: 20, enumType: CommunityRole::class)]
    #[Groups(['member:read'])]
    private CommunityRole $role = CommunityRole::Member;

    #[ORM\Column]
    #[Groups(['member:read'])]
    private \DateTimeImmutable $joinedAt;

    #[ORM\Column(options: ['default' => false])]
    private bool $notificationsMuted = false;

    public function __construct()
    {
        $this->joinedAt = new \DateTimeImmutable();
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

    public function getCommunity(): Community
    {
        return $this->community;
    }

    public function setCommunity(Community $community): static
    {
        $this->community = $community;

        return $this;
    }

    public function getRole(): CommunityRole
    {
        return $this->role;
    }

    public function setRole(CommunityRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    #[Groups(['member:read'])]
    public function getProfile(): ?Profile
    {
        return $this->user->getProfile();
    }

    #[Groups(['member:read'])]
    public function getUserId(): ?int
    {
        return $this->user->getId();
    }

    public function isNotificationsMuted(): bool
    {
        return $this->notificationsMuted;
    }

    public function setNotificationsMuted(bool $notificationsMuted): static
    {
        $this->notificationsMuted = $notificationsMuted;

        return $this;
    }
}
