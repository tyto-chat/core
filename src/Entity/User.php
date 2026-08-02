<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\User\ChangeRolesDto;
use App\Dto\User\CreateUserDto;
use App\Dto\User\SetEmailNotificationsDto;
use App\Enum\User\UserRole;
use App\Repository\UserRepository;
use App\State\User\Processor\ChangeRolesProcessor;
use App\State\User\Processor\CompleteOnboardingProcessor;
use App\State\User\Processor\DeleteUserProcessor;
use App\State\User\Processor\RegisterUserProcessor;
use App\State\User\Processor\SetEmailNotificationsProcessor;
use App\State\User\Provider\MeProvider;
use App\State\User\Provider\UserProvider;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Blameable\Traits\BlameableEntity;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'This email is already in use.')]
#[ApiResource(
    description: 'A user account on this server, with its public profile and global roles.',
    operations: [
        new Get(
            uriTemplate: '/users/{id}',
            requirements: ['id' => '\d+'],
            security: "is_granted('ROLE_USER')",
            provider: UserProvider::class,
            extraProperties: ['scopeResource' => 'profile'],
            openapi: new Model\Operation(
                summary: 'Get a user by id',
                description: 'Any authenticated user. Returns the public representation of a user; '
                    .'private fields such as `email` and `roles` are only included when the caller '
                    .'requests their own account or is a global admin. `404` when no user has the id.',
            ),
        ),
        new Get(
            uriTemplate: '/me',
            security: "is_granted('ROLE_USER')",
            provider: MeProvider::class,
            extraProperties: ['scopeResource' => 'profile'],
            openapi: new Model\Operation(
                summary: 'Get the currently authenticated user',
                description: 'The authenticated user. Returns the caller\'s own account including '
                    .'private fields (`email`, `roles`, `emailNotifications`).',
            ),
        ),
        new GetCollection(
            security: "is_granted('ROLE_ADMIN')",
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'List all user accounts',
                description: 'Global admin only. Returns every account on the server; filterable by '
                    .'partial `email` and `profile.name`.',
            ),
        ),
        new Post(
            input: CreateUserDto::class,
            processor: RegisterUserProcessor::class,
            openapi: new Model\Operation(
                summary: 'Register a new account',
                description: 'Anonymous. Creates a user account with the given email, display name and '
                    .'password. `422` when registration is disabled by the operator, the terms are not '
                    .'accepted while consent is required, the date of birth fails the minimum-age gate, '
                    .'or the email is already in use.',
            ),
        ),
        new Post(
            uriTemplate: '/me/email-notifications',
            security: "is_granted('ROLE_USER')",
            input: SetEmailNotificationsDto::class,
            processor: SetEmailNotificationsProcessor::class,
            extraProperties: ['scopeResource' => 'profile'],
            openapi: new Model\Operation(
                summary: 'Toggle the daily email digest',
                description: 'The authenticated user. Enables or disables the daily notification email '
                    .'digest and pins the caller\'s current request locale so the scheduler can render '
                    .'the digest in it. Returns the updated user.',
            ),
        ),
        new Post(
            uriTemplate: '/me/onboarding/complete',
            security: "is_granted('ROLE_USER')",
            input: false,
            processor: CompleteOnboardingProcessor::class,
            openapi: new Model\Operation(
                summary: 'Mark first-run onboarding as complete',
                description: 'The authenticated user. Stamps `onboardedAt` after the first-run wizard; '
                    .'idempotent — subsequent calls keep the original timestamp. No request body.',
            ),
        ),
        new Patch(
            input: ChangeRolesDto::class,
            processor: ChangeRolesProcessor::class,
            security: "is_granted('ROLE_ADMIN')",
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'Replace a user\'s global roles',
                description: 'Global admin only. Overwrites the user\'s global role list (e.g. grants '
                    .'or revokes `ROLE_ADMIN`).',
            ),
        ),
        new Delete(
            security: "is_granted('ROLE_ADMIN')",
            processor: DeleteUserProcessor::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'Delete a user account',
                description: 'Global admin only. Anonymises the account in place (GDPR purge): wipes '
                    .'PII and credentials, revokes tokens and blocks login. Authored content is kept '
                    .'under a "Deleted user" identity.',
            ),
        ),
    ],
    normalizationContext: ['groups' => ['user:read']],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'email' => 'partial',
    'profile.name' => 'partial',
])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use BlameableEntity;
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['user:read', 'message:read', 'member:read', 'user:embed'])]
    private ?int $id = null;

    // PII — must stay `user:read:self` (UserNormalizer), never `user:read`, or email leaks on other users' reads.
    #[ORM\Column(length: 180)]
    #[Groups(['user:update', 'user:read:self'])]
    private ?string $email = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isBot = false;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['user:read:self'])]
    private bool $emailNotifications = false;

    #[ORM\Column(length: 8, options: ['default' => 'en'])]
    private string $locale = 'en';

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletionRequestedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['user:read'])]
    private ?\DateTimeImmutable $onboardedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $termsAcceptedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $ageVerifiedAt = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column(type: 'json')]
    #[Groups(['user:read:self'])]
    private array $roles = [];

    #[Groups(['user:read', 'message:read', 'member:read', 'user:embed'])]
    #[SerializedName('isAdmin')]
    public function isAdmin(): bool
    {
        return in_array(UserRole::Admin->value, $this->roles, true);
    }

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    #[Assert\NotBlank(message: 'Password cannot be blank.')]
    #[Assert\Length(min: 8, max: 64)]
    private ?string $password = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $twoFactorSecret = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $totpEnabledAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $totpLastUsedTimestep = null;

    #[ORM\OneToOne(mappedBy: 'user', cascade: ['persist', 'remove'])]
    #[Groups(['user:read', 'message:read', 'member:read', 'user:embed'])]
    private Profile $profile;

    public function __construct()
    {
        $profile = new Profile();
        $profile->setName('');
        $profile->setUser($this);

        $this->profile = $profile;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function isEmailNotifications(): bool
    {
        return $this->emailNotifications;
    }

    public function setEmailNotifications(bool $emailNotifications): static
    {
        $this->emailNotifications = $emailNotifications;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getDeletionRequestedAt(): ?\DateTimeImmutable
    {
        return $this->deletionRequestedAt;
    }

    public function setDeletionRequestedAt(?\DateTimeImmutable $deletionRequestedAt): static
    {
        $this->deletionRequestedAt = $deletionRequestedAt;

        return $this;
    }

    public function isPendingDeletion(): bool
    {
        return null !== $this->deletionRequestedAt;
    }

    #[\Override]
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    #[\Override]
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = UserRole::User->value;

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /**
     * Deprecated in Symfony 7.3; nothing transient to erase — marking it skips the framework call.
     */
    #[\Deprecated]
    #[\Override]
    public function eraseCredentials(): void
    {
    }

    public function isBot(): bool
    {
        return $this->isBot;
    }

    public function setIsBot(bool $isBot): static
    {
        $this->isBot = $isBot;

        return $this;
    }

    #[\Override]
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them, as supported since Symfony 7.3.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = null !== $this->password ? hash('crc32c', $this->password) : null;

        return $data;
    }

    public function getOnboardedAt(): ?\DateTimeImmutable
    {
        return $this->onboardedAt;
    }

    public function getTermsAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->termsAcceptedAt;
    }

    public function setTermsAcceptedAt(?\DateTimeImmutable $termsAcceptedAt): self
    {
        $this->termsAcceptedAt = $termsAcceptedAt;

        return $this;
    }

    public function getAgeVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->ageVerifiedAt;
    }

    public function setAgeVerifiedAt(?\DateTimeImmutable $ageVerifiedAt): self
    {
        $this->ageVerifiedAt = $ageVerifiedAt;

        return $this;
    }

    public function markOnboarded(): self
    {
        $this->onboardedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getProfile(): ?Profile
    {
        return $this->profile;
    }

    public function getTwoFactorSecret(): ?string
    {
        return $this->twoFactorSecret;
    }

    public function setTwoFactorSecret(?string $twoFactorSecret): static
    {
        $this->twoFactorSecret = $twoFactorSecret;

        return $this;
    }

    public function getTotpEnabledAt(): ?\DateTimeImmutable
    {
        return $this->totpEnabledAt;
    }

    public function setTotpEnabledAt(?\DateTimeImmutable $totpEnabledAt): static
    {
        $this->totpEnabledAt = $totpEnabledAt;

        return $this;
    }

    public function getTotpLastUsedTimestep(): ?int
    {
        return $this->totpLastUsedTimestep;
    }

    public function setTotpLastUsedTimestep(?int $totpLastUsedTimestep): static
    {
        $this->totpLastUsedTimestep = $totpLastUsedTimestep;

        return $this;
    }

    public function isTwoFactorEnabled(): bool
    {
        return null !== $this->totpEnabledAt;
    }
}
