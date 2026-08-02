<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\CommunityPin\PinCommunityDto;
use App\Dto\CommunityPin\ReorderPinnedCommunitiesDto;
use App\Repository\CommunityPinRepository;
use App\State\CommunityPin\Processor\PinCommunityProcessor;
use App\State\CommunityPin\Processor\ReorderPinnedCommunitiesProcessor;
use App\State\CommunityPin\Processor\UnpinCommunityProcessor;
use App\State\CommunityPin\Provider\PinnedCommunitiesProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: CommunityPinRepository::class)]
#[ORM\UniqueConstraint(name: 'community_pin_user_community_unique', fields: ['user', 'community'])]
#[ORM\Index(name: 'community_pin_user_position_idx', fields: ['user', 'position'])]
#[ApiResource(
    description: 'A per-user record that a community is pinned to the left rail, at a 0-indexed position.',
    operations: [
        new GetCollection(
            uriTemplate: '/me/pinned-communities',
            security: "is_granted('ROLE_USER')",
            paginationEnabled: false,
            provider: PinnedCommunitiesProvider::class,
            extraProperties: ['scopeResource' => 'communities'],
            openapi: new Model\Operation(
                summary: 'List my pinned communities',
                description: 'Returns the caller\'s pins in rail order, each embedding its community. '
                    .'Unpaginated.',
            ),
        ),
        new Post(
            uriTemplate: '/me/pinned-communities',
            security: "is_granted('ROLE_USER')",
            input: PinCommunityDto::class,
            processor: PinCommunityProcessor::class,
            extraProperties: ['scopeResource' => 'communities'],
            openapi: new Model\Operation(
                summary: 'Pin a community to the rail',
                description: 'Pins the community given by `communityId` for the caller, appended at the '
                    .'end of the rail. Idempotent — pinning again returns the existing pin. Requires '
                    .'permission to view the community: private communities the caller is not a member '
                    .'of return `403`; unknown ids return `404`.',
            ),
        ),
        new Delete(
            uriTemplate: '/me/pinned-communities/{communityId}',
            uriVariables: ['communityId' => new Link(fromClass: Community::class, identifiers: ['id'])],
            requirements: ['communityId' => '\d+'],
            security: "is_granted('ROLE_USER')",
            read: false,
            processor: UnpinCommunityProcessor::class,
            extraProperties: ['scopeResource' => 'communities'],
            openapi: new Model\Operation(
                summary: 'Unpin a community from the rail',
                description: 'Keyed by the community\'s numeric id, not the pin id. Idempotent — '
                    .'unpinning a community that is not pinned is a `204` no-op; an unknown community '
                    .'returns `404`.',
            ),
        ),
        new Post(
            uriTemplate: '/me/pinned-communities/order',
            security: "is_granted('ROLE_USER')",
            input: ReorderPinnedCommunitiesDto::class,
            output: false,
            processor: ReorderPinnedCommunitiesProcessor::class,
            extraProperties: ['scopeResource' => 'communities'],
            openapi: new Model\Operation(
                summary: 'Reorder my pinned communities',
                description: 'The payload must list exactly the community ids of the caller\'s current '
                    .'pins, each once, in the desired order; anything else returns `422`. Returns no body.',
            ),
        ),
    ],
    normalizationContext: ['groups' => ['community_pin:read', 'community:read']],
    denormalizationContext: ['groups' => ['community_pin:write']],
)]
class CommunityPin
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Community::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['community_pin:read'])]
    private Community $community;

    #[ORM\Column]
    #[Groups(['community_pin:read'])]
    private int $position = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
