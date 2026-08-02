<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model;
use App\Dto\Profile\UpdateProfileDto;
use App\Repository\ProfileRepository;
use App\State\Profile\Processor\UpdateProfileProcessor;
use App\State\Profile\Provider\ProfileProvider;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Blameable\Traits\BlameableEntity;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: ProfileRepository::class)]
#[ApiResource(
    description: 'A user\'s public profile: display name, avatar, bio, location, website and birthday.',
    operations: [
        new Get(
            security: "is_granted('ROLE_USER')",
            provider: ProfileProvider::class,
            extraProperties: ['scopeResource' => 'profile'],
            openapi: new Model\Operation(
                summary: 'Get a profile by id',
                description: 'Any authenticated user. Returns the public profile fields. '
                    .'`404` when no profile has the id.',
            ),
        ),
        new Patch(
            security: "is_granted('ROLE_USER')",
            input: UpdateProfileDto::class,
            processor: UpdateProfileProcessor::class,
            extraProperties: ['scopeResource' => 'profile'],
            openapi: new Model\Operation(
                summary: 'Update a profile',
                description: 'The profile owner or a global admin. Sparse merge-patch of display name, '
                    .'avatar, bio, location, website and birthday. `422` when the referenced avatar '
                    .'media object is not of type `avatar`.',
            ),
        ),
    ],
    normalizationContext: ['groups' => ['user:read']],
)]
class Profile
{
    use BlameableEntity;
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['user:read', 'message:read', 'member:read', 'channel_member:read', 'channel_participant:read', 'conversation:read', 'conversation_member:read', 'user:embed'])]
    private ?string $name = null;

    #[ORM\OneToOne(inversedBy: 'profile', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\OneToOne(cascade: ['remove'])]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['user:read', 'message:read', 'member:read', 'channel_member:read', 'channel_participant:read', 'conversation:read', 'conversation_member:read', 'user:embed'])]
    private ?MediaObject $avatar = null;

    #[ORM\Column(length: 190, nullable: true)]
    #[Groups(['user:read'])]
    private ?string $bio = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['user:read'])]
    private ?string $location = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['user:read'])]
    private ?string $website = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Groups(['user:read', 'message:read', 'member:read', 'channel_member:read', 'channel_participant:read', 'conversation:read', 'conversation_member:read', 'user:embed'])]
    private ?int $birthdayMonth = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Groups(['user:read', 'message:read', 'member:read', 'channel_member:read', 'channel_participant:read', 'conversation:read', 'conversation_member:read', 'user:embed'])]
    private ?int $birthdayDay = null;

    public function getId(): ?int
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getAvatar(): ?MediaObject
    {
        return $this->avatar;
    }

    public function setAvatar(?MediaObject $avatar): static
    {
        $this->avatar = $avatar;

        return $this;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): static
    {
        $this->bio = $bio;

        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): static
    {
        $this->website = $website;

        return $this;
    }

    public function getBirthdayMonth(): ?int
    {
        return $this->birthdayMonth;
    }

    public function setBirthdayMonth(?int $birthdayMonth): static
    {
        $this->birthdayMonth = $birthdayMonth;

        return $this;
    }

    public function getBirthdayDay(): ?int
    {
        return $this->birthdayDay;
    }

    public function setBirthdayDay(?int $birthdayDay): static
    {
        $this->birthdayDay = $birthdayDay;

        return $this;
    }
}
