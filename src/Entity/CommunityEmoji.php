<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\Repository\CommunityEmojiRepository;
use App\Service\Community\CommunityEmojiServiceInterface;
use App\State\CommunityEmoji\Processor\DeleteCommunityEmojiProcessor;
use App\State\CommunityEmoji\Provider\CommunityEmojisProvider;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Blameable\Traits\BlameableEntity;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CommunityEmojiRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_COMMUNITY_EMOJI_SHORTCODE', columns: ['community_id', 'shortcode'])]
#[ApiResource(
    description: 'A custom uploaded emoji registered on a community\'s allowlist, keyed by its `:shortcode:`.',
    normalizationContext: ['groups' => ['community_emoji:read']],
    denormalizationContext: ['groups' => ['community_emoji:create']],
)]
#[GetCollection(
    uriTemplate: '/communities/{community}/emojis',
    uriVariables: [
        'community' => new Link(fromClass: Community::class, toProperty: 'community', identifiers: ['identifier']),
    ],
    provider: CommunityEmojisProvider::class,
    extraProperties: ['tyto_http_cache' => true, 'scopeResource' => 'communities'],
    cacheHeaders: ['vary' => ['Content-Type', 'Origin']],
    openapi: new Model\Operation(
        summary: 'List community emojis',
        description: 'Anonymous on public communities; private communities require membership or '
            .'global admin. Served through the shared HTTP cache.',
    ),
)]
#[Delete(
    security: "is_granted('ROLE_USER')",
    processor: DeleteCommunityEmojiProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Delete a community emoji',
        description: 'Requires community admin. Removes the emoji and its uploaded image, drops every '
            .'reaction using its shortcode from messages (republishing affected messages via Mercure), '
            .'then publishes an emoji-list update and purges the cached emoji collection.',
    ),
)]
class CommunityEmoji
{
    use BlameableEntity;
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[ApiProperty(identifier: true)]
    #[Groups(['community_emoji:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Community::class, inversedBy: 'emojis')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Community $community = null;

    #[ORM\Column(length: 36)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 4, max: 36)]
    #[Assert\Regex(pattern: CommunityEmojiServiceInterface::SHORTCODE_PATTERN, message: 'Shortcode must match :[a-z0-9_-]{2,32}: format.')]
    #[Groups(['community_emoji:read'])]
    private string $shortcode = '';

    #[ORM\Column(length: 64, nullable: true)]
    #[Assert\Length(max: 64)]
    #[Groups(['community_emoji:read'])]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: MediaObject::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['community_emoji:read'])]
    private ?MediaObject $image = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Groups(['community_emoji:read'])]
    private int $position = 0;

    /**
     * Trait property must stay untyped (PHP forbids retyping); group lives on the typed getter so createdBy serialises as a bare IRI — no PII in the shared HTTP cache.
     *
     * @var User|null
     */
    #[Gedmo\Blameable(on: 'create')]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected $createdBy;

    /** @var User|null */
    #[Gedmo\Blameable(on: 'update')]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected $updatedBy;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getShortcode(): string
    {
        return $this->shortcode;
    }

    public function setShortcode(string $shortcode): static
    {
        $this->shortcode = $shortcode;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = null === $name ? null : ('' === trim($name) ? null : trim($name));

        return $this;
    }

    public function getImage(): ?MediaObject
    {
        return $this->image;
    }

    public function setImage(?MediaObject $image): static
    {
        $this->image = $image;

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

    #[Groups(['community_emoji:read'])]
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

    #[Groups(['community_emoji:read'])]
    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    #[Groups(['community_emoji:read'])]
    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }
}
