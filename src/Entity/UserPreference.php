<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model;
use App\Dto\UserPreference\UpdateUserPreferencesDto;
use App\Enum\Settings\SupportedLocale;
use App\Enum\User\UserSubmitKey;
use App\Enum\User\UserTheme;
use App\Repository\UserPreferenceRepository;
use App\State\UserPreference\Processor\SaveUserPreferenceProcessor;
use App\State\UserPreference\Provider\UserPreferenceProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: UserPreferenceRepository::class)]
#[ORM\Table(name: 'user_preference')]
#[ApiResource(
    description: 'The caller\'s server-persisted UI and behaviour preferences (one row per user).',
    operations: [
        new Get(
            uriTemplate: '/me/preferences',
            security: "is_granted('ROLE_USER')",
            provider: UserPreferenceProvider::class,
            extraProperties: ['scopeResource' => 'profile'],
            openapi: new Model\Operation(
                summary: 'Get the caller\'s preferences',
                description: 'The authenticated user. Returns the caller\'s preference singleton, '
                    .'auto-created on first read so it never returns `404`. A `null` field means '
                    .'"use the client/system default".',
            ),
        ),
        new Patch(
            uriTemplate: '/me/preferences',
            security: "is_granted('ROLE_USER')",
            input: UpdateUserPreferencesDto::class,
            provider: UserPreferenceProvider::class,
            processor: SaveUserPreferenceProcessor::class,
            extraProperties: ['scopeResource' => 'profile'],
            openapi: new Model\Operation(
                summary: 'Update the caller\'s preferences',
                description: 'The authenticated user. Sparse merge-patch: an omitted field is left '
                    .'unchanged, an explicit `null` clears the field back to the default. `422` on an '
                    .'invalid enum value or type.',
            ),
        ),
    ],
    normalizationContext: ['groups' => ['user_preference:read']],
    denormalizationContext: ['groups' => ['user_preference:write']],
)]
class UserPreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, nullable: true, enumType: UserTheme::class)]
    #[Groups(['user_preference:read'])]
    private ?UserTheme $theme = null;

    #[ORM\Column(length: 16, nullable: true, enumType: UserSubmitKey::class)]
    #[Groups(['user_preference:read'])]
    private ?UserSubmitKey $submitKey = null;

    #[ORM\Column(length: 8, nullable: true, enumType: SupportedLocale::class)]
    #[Groups(['user_preference:read'])]
    private ?SupportedLocale $locale = null;

    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['user_preference:read'])]
    private ?string $timezone = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['user_preference:read'])]
    private ?bool $sendTypingIndicator = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['user_preference:read'])]
    private ?bool $desktopNotifications = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['user_preference:read'])]
    private ?bool $convertEmoticons = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['user_preference:read'])]
    private ?bool $resumeLastLocation = null;

    /**
     * @var array<string, bool>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['user_preference:read'])]
    private ?array $sectionCollapse = null;

    #[ORM\Column]
    #[Groups(['user_preference:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTheme(): ?UserTheme
    {
        return $this->theme;
    }

    public function setTheme(?UserTheme $theme): self
    {
        $this->theme = $theme;

        return $this;
    }

    public function getSubmitKey(): ?UserSubmitKey
    {
        return $this->submitKey;
    }

    public function setSubmitKey(?UserSubmitKey $submitKey): self
    {
        $this->submitKey = $submitKey;

        return $this;
    }

    public function getLocale(): ?SupportedLocale
    {
        return $this->locale;
    }

    public function setLocale(?SupportedLocale $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    public function setTimezone(?string $timezone): self
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function getSendTypingIndicator(): ?bool
    {
        return $this->sendTypingIndicator;
    }

    public function setSendTypingIndicator(?bool $value): self
    {
        $this->sendTypingIndicator = $value;

        return $this;
    }

    public function getDesktopNotifications(): ?bool
    {
        return $this->desktopNotifications;
    }

    public function setDesktopNotifications(?bool $value): self
    {
        $this->desktopNotifications = $value;

        return $this;
    }

    public function getConvertEmoticons(): ?bool
    {
        return $this->convertEmoticons;
    }

    public function setConvertEmoticons(?bool $value): self
    {
        $this->convertEmoticons = $value;

        return $this;
    }

    public function getResumeLastLocation(): ?bool
    {
        return $this->resumeLastLocation;
    }

    public function setResumeLastLocation(?bool $value): self
    {
        $this->resumeLastLocation = $value;

        return $this;
    }

    /** @return array<string, bool>|null */
    public function getSectionCollapse(): ?array
    {
        return $this->sectionCollapse;
    }

    /** @param array<string, bool>|null $value */
    public function setSectionCollapse(?array $value): self
    {
        $this->sectionCollapse = $value;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
