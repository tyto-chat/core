<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model;
use App\Dto\Channel\ReorderChannelsDto;
use App\Dto\ChannelSection\UpdateChannelSectionDto;
use App\Repository\ChannelSectionRepository;
use App\State\Channel\Processor\ReorderChannelsProcessor;
use App\State\ChannelSection\Processor\RemoveChannelSectionProcessor;
use App\State\ChannelSection\Processor\UpdateChannelSectionProcessor;
use App\State\ChannelSection\Provider\ChannelSectionProvider;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Blameable\Traits\BlameableEntity;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ChannelSectionRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_CHANNEL_SECTION_IDENTIFIER', fields: ['identifier', 'community'])]
#[ApiResource(
    description: 'A named grouping of channels inside a community, ordered by position in the sidebar.',
    normalizationContext: ['groups' => ['section:read']],
    denormalizationContext: ['groups' => ['section:create', 'section:update']],
)]
#[Delete(
    uriTemplate: '/communities/{community}/sections/{id}',
    requirements: ['id' => '\\d+'],
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'id' => new Link(fromClass: ChannelSection::class, identifiers: ['id']),
    ],
    security: "is_granted('ROLE_USER')",
    processor: RemoveChannelSectionProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Delete a channel section',
        description: 'Requires community admin. Only empty sections can be deleted — a section that '
            .'still contains channels returns `422`. Publishes a `community.structure` Mercure event.',
    ),
)]
#[Get(
    uriTemplate: '/communities/{community}/sections/{id}',
    requirements: ['id' => '\\d+'],
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'id' => new Link(fromClass: ChannelSection::class, identifiers: ['id']),
    ],
    security: "is_granted('ROLE_USER')",
    provider: ChannelSectionProvider::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Get a channel section',
        description: 'Any authenticated user who may view the owning community (private communities '
            .'require membership or global admin). Includes the section\'s channels in position order.',
    ),
)]
#[Patch(
    uriTemplate: '/communities/{community}/sections/{id}',
    requirements: ['id' => '\\d+'],
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'id' => new Link(fromClass: ChannelSection::class, identifiers: ['id']),
    ],
    security: "is_granted('ROLE_USER')",
    input: UpdateChannelSectionDto::class,
    processor: UpdateChannelSectionProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Update a channel section',
        description: 'Requires community admin. Renames the section (its slug identifier follows the '
            .'name). Publishes a `community.structure` Mercure event.',
    ),
)]
#[Put(
    uriTemplate: '/communities/{community}/sections/{id}',
    requirements: ['id' => '\\d+'],
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'id' => new Link(fromClass: ChannelSection::class, identifiers: ['id']),
    ],
    security: "is_granted('ROLE_USER')",
    input: UpdateChannelSectionDto::class,
    processor: UpdateChannelSectionProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Replace a channel section',
        description: 'Requires community admin. Same behavior as `PATCH`: renames the section (its slug '
            .'identifier follows the name) and publishes a `community.structure` Mercure event.',
    ),
)]
#[Put(
    uriTemplate: '/communities/{community}/sections/{id}/channels/order',
    requirements: ['id' => '\\d+'],
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
        'id' => new Link(fromClass: ChannelSection::class, identifiers: ['id']),
    ],
    security: "is_granted('ROLE_USER')",
    input: ReorderChannelsDto::class,
    output: false,
    status: 204,
    read: false,
    processor: ReorderChannelsProcessor::class,
    denormalizationContext: ['groups' => ['channel_reorder:write']],
    formats: ['jsonld' => ['application/ld+json'], 'json' => ['application/json']],
    extraProperties: ['standard_put' => false, 'scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Reorder channels within a section',
        description: 'Requires community admin. The payload must list exactly the channel ids of the '
            .'section, each once, in the desired order; anything else returns `422`. Returns `204` '
            .'and publishes a `community.structure` Mercure event.',
    ),
)]
class ChannelSection
{
    use BlameableEntity;
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['community:read', 'channel:read', 'section:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['community:read', 'channel:read', 'section:read', 'section:create', 'section:update'])]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    #[Gedmo\Slug(fields: ['name'], updatable: true)]
    #[Groups(['community:read', 'channel:read', 'section:read'])]
    private ?string $identifier = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['section:read', 'community:read'])]
    private int $position = 0;

    #[ORM\ManyToOne(inversedBy: 'channelSections')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['section:create'])]
    private ?Community $community = null;

    /**
     * @var Collection<int, Channel>
     */
    #[ORM\OneToMany(targetEntity: Channel::class, mappedBy: 'section')]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Groups(['section:read'])]
    private Collection $channels;

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
    }

    public function getId(): int
    {
        return $this->id;
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

    public function getCommunity(): ?Community
    {
        return $this->community;
    }

    public function setCommunity(?Community $community): static
    {
        $this->community = $community;

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
            $channel->setSection($this);
        }

        return $this;
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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

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
}
