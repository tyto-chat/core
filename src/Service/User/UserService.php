<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Dto\User\ChangePasswordDto;
use App\Dto\User\CreateUserDto;
use App\Entity\MediaObject;
use App\Entity\User;
use App\Enum\IpReputation\IpReputationVerdict;
use App\Enum\User\UserRole;
use App\Exception\User\EmailAlreadyInUseException;
use App\Exception\User\InvalidCurrentPasswordException;
use App\Exception\User\RegistrationBlockedException;
use App\Exception\User\RegistrationDisabledException;
use App\Exception\User\TermsNotAcceptedException;
use App\Exception\User\UnderageRegistrationException;
use App\Exception\User\UserNotFoundException;
use App\Repository\UserRepository;
use App\Security\SecurityContext;
use App\Service\AbstractDoctrineService;
use App\Service\Challenge\ChallengeServiceInterface;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\IpReputation\IpReputationServiceInterface;
use App\Service\MediaObject\MediaObjectServiceInterface;
use App\Service\Notification\NotificationServiceInterface;
use App\Service\Security\SessionRevokerInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\DependencyInjection\Attribute\Lazy;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserService extends AbstractDoctrineService implements UserServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly MediaObjectServiceInterface $mediaObjectService,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepository,
        private readonly CommunityMembershipServiceInterface $membership,
        private readonly ChallengeServiceInterface $challengeService,
        private readonly SettingsServiceInterface $settings,
        private readonly IpReputationServiceInterface $ipReputation,
        private readonly RequestStack $requestStack,
        private readonly SessionRevokerInterface $sessionRevoker,
        #[Lazy]
        private readonly ConversationServiceInterface $conversationService,
        private readonly NotificationServiceInterface $notificationService,
    ) {
    }

    #[\Override]
    public function find(int $id): ?User
    {
        return $this->userRepository->find($id);
    }

    #[\Override]
    public function findByEmail(string $email): ?User
    {
        return $this->userRepository->findOneBy(['email' => $email]);
    }

    #[\Override]
    public function existsByEmail(string $email): bool
    {
        return null !== $this->userRepository->findOneBy(['email' => $email]);
    }

    #[\Override]
    public function get(int $id): User
    {
        $this->security->throwAccessDeniedUnlessAuthenticated('You must be signed in to view user profiles.');
        $user = $this->userRepository->find($id);
        if (!$user) {
            throw new UserNotFoundException(sprintf('User with id "%d" not found.', $id));
        }

        return $user;
    }

    #[\Override]
    public function findByIds(array $ids): array
    {
        return $this->userRepository->findBy(['id' => $ids]);
    }

    #[\Override]
    public function getAdmins(): array
    {
        return $this->userRepository->findAdmins();
    }

    #[\Override]
    public function register(CreateUserDto $dto): User
    {
        if (!$this->settings->get(Settings::registrationEnabled())) {
            throw new RegistrationDisabledException('Registration is disabled by the server operator.');
        }

        $ip = $this->requestStack->getCurrentRequest()?->getClientIp();
        if (null !== $ip && IpReputationVerdict::Flagged === $this->ipReputation->check($ip, $dto->email, $dto->displayName)) {
            $contact = $this->settings->get(Settings::ipReputationAppealContact());
            $this->logger->info(sprintf('Registration blocked by IP reputation for IP "%s".', $ip));

            throw new RegistrationBlockedException('' !== $contact ? $contact : null);
        }

        if ($this->settings->get(Settings::requireRegistrationConsent()) && !$dto->acceptedTerms) {
            throw new TermsNotAcceptedException('You must accept the terms of service and privacy policy to register.');
        }

        $ageVerified = $this->verifyAge($dto->dateOfBirth);

        if (null !== $dto->challengeToken) {
            $challenge = $this->challengeService->get($dto->email, $dto->challengeToken);
            $this->challengeService->consume($challenge);
        }

        try {
            $user = $this->build($dto);
            if ($dto->acceptedTerms) {
                $user->setTermsAcceptedAt(new \DateTimeImmutable());
            }
            if ($ageVerified) {
                $user->setAgeVerifiedAt(new \DateTimeImmutable());
            }
            $this->save($user, $dto);
        } catch (UniqueConstraintViolationException $e) {
            throw new EmailAlreadyInUseException('This email is already in use.', previous: $e);
        }

        $this->logger->info(sprintf('A new user account was registered using email "%s".', $user->getEmail()));

        return $user;
    }

    private function verifyAge(?string $dateOfBirth): bool
    {
        $minimumAge = (int) $this->settings->get(Settings::minimumAgeYears());
        if ($minimumAge <= 0) {
            return false;
        }

        if (null === $dateOfBirth || '' === trim($dateOfBirth)) {
            throw new UnderageRegistrationException('A date of birth is required to register on this server.');
        }

        $dob = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateOfBirth);
        $now = new \DateTimeImmutable();
        if (false === $dob || $dob > $now) {
            throw new UnderageRegistrationException('The provided date of birth is invalid.');
        }

        if ($dob->diff($now)->y < $minimumAge) {
            throw new UnderageRegistrationException(sprintf('You must be at least %d years old to register on this server.', $minimumAge));
        }

        return true;
    }

    #[\Override]
    public function new(CreateUserDto $createUserDto, array $roles = []): User
    {
        return $this->save($this->build($createUserDto, $roles), $createUserDto);
    }

    /** @param list<string> $roles */
    private function build(CreateUserDto $createUserDto, array $roles = []): User
    {
        $user = new User();
        $user->setPassword($this->passwordHasher->hashPassword($user, $createUserDto->plainPassword));
        $user->getProfile()->setName($createUserDto->displayName);
        $user->setRoles($roles);

        return $user;
    }

    #[\Override]
    public function createBatch(iterable $dtos, int $batchSize = 100, ?callable $onProgress = null): void
    {
        $count = 0;
        foreach ($dtos as $dto) {
            $user = new User();
            $user->setPassword($this->passwordHasher->hashPassword($user, $dto->plainPassword));
            $user->getProfile()->setName($dto->displayName);
            $dto->applyTo($user);
            $this->persist($user);
            ++$count;

            if (0 === $count % $batchSize) {
                $this->flush();
                $this->clear();
                if (null !== $onProgress) {
                    $onProgress($count);
                }
            }
        }

        if (0 !== $count % $batchSize) {
            $this->flush();
            $this->clear();
            if (null !== $onProgress) {
                $onProgress($count);
            }
        }
    }

    #[\Override]
    public function createInvited(string $name, string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(32))));
        $user->getProfile()->setName($name);

        return $this->save($user);
    }

    #[\Override]
    public function newBot(string $displayName): User
    {
        // Null token (CLI/worker) = trusted operator context; with a token the caller must be admin.
        $this->security->throwAccessDeniedIf(null !== $this->security->getUser() && !$this->security->isAdmin(), 'Only admins can create bot users.');

        $user = new User();
        $user->setEmail(sprintf('bot-%s@bot.invalid', bin2hex(random_bytes(8))));
        $user->setPassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(32))));
        $user->setIsBot(true);
        // ROLE_ADMIN lets bots pass community-mod gates when invoked via runAs.
        $user->setRoles([UserRole::Admin->value]);
        $user->getProfile()->setName($displayName);

        return $this->save($user);
    }

    #[\Override]
    public function updatePassword(User $user, string $newPassword): void
    {
        $this->security->throwAccessDeniedUnlessAdmin();
        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
        $this->save($user);
        $this->sessionRevoker->revokeRefreshTokens($user);
    }

    #[\Override]
    public function changePassword(ChangePasswordDto $dto): void
    {
        $user = $this->security->currentUser();

        if (!$this->passwordHasher->isPasswordValid($user, $dto->currentPassword)) {
            throw new InvalidCurrentPasswordException('Current password is incorrect.');
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $dto->newPassword));
        $this->save($user);
        $this->sessionRevoker->revokeRefreshTokens($user);
    }

    #[\Override]
    public function updateRoles(User $user, array $roles): User
    {
        $this->security->throwAccessDeniedUnlessAdmin();
        $user->setRoles($roles);

        return $this->save($user);
    }

    #[\Override]
    public function grantAdminRole(User $user): User
    {
        $roles = array_values(array_filter(
            $user->getRoles(),
            static fn (string $role): bool => UserRole::User->value !== $role,
        ));
        $roles[] = UserRole::Admin->value;
        $user->setRoles(array_values(array_unique($roles)));

        return $this->save($user);
    }

    #[\Override]
    public function adminSetDisplayName(User $user, string $displayName): User
    {
        $this->security->throwAccessDeniedUnlessAdmin();

        $user->getProfile()->setName($displayName);

        return $this->save($user);
    }

    #[\Override]
    public function findInvitableForCurrentUser(?string $search, int $limit): array
    {
        $caller = $this->security->currentUser('You must be signed in to list invitable users.');
        $limit = max(1, min(50, $limit));

        if (null === $search || '' === trim($search)) {
            $users = $this->findSuggestedContacts($caller, $limit);
        } else {
            $users = $this->security->isAdmin()
                ? $this->userRepository->searchNonBot($search, $limit)
                : $this->membership->findUsersSharingCommunity($caller, $search, $limit);
        }

        $items = [];
        foreach ($users as $user) {
            $id = $user->getId();
            if (null === $id) {
                continue;
            }
            $items[] = [
                'id' => $id,
                'name' => $user->getProfile()?->getName(),
                'avatar' => $user->getProfile()?->getAvatar(),
            ];
        }

        return $items;
    }

    /** @return User[] */
    private function findSuggestedContacts(User $caller, int $limit): array
    {
        $ids = $this->conversationService->findRecentPartnerUserIds($caller, $limit);
        foreach ($this->notificationService->findRecentMentionerUserIds($caller, $limit) as $id) {
            if (!\in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        $callerId = (int) $caller->getId();
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id !== $callerId));
        if ([] === $ids) {
            return [];
        }

        $usersById = [];
        foreach ($this->userRepository->findBy(['id' => $ids]) as $user) {
            $usersById[(int) $user->getId()] = $user;
        }

        $isAdmin = $this->security->isAdmin();
        $result = [];
        foreach ($ids as $id) {
            $user = $usersById[$id] ?? null;
            if (null === $user || $user->isBot()) {
                continue;
            }
            if (!$isAdmin && !$this->membership->existsSharedCommunity($caller, $user)) {
                continue;
            }
            $result[] = $user;
            if (\count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    #[\Override]
    public function setAvatar(MediaObject $avatar): void
    {
        $user = $this->security->currentUser('You must be signed in to update your avatar.');
        $oldAvatar = $user->getProfile()->getAvatar();
        $this->mediaObjectService->prepare($avatar, 'avatar');
        $user->getProfile()->setAvatar($avatar);
        $this->save($user);

        if (null !== $oldAvatar && $oldAvatar !== $avatar) {
            $this->mediaObjectService->delete($oldAvatar);
        }
    }

    #[\Override]
    public function setEmailNotifications(bool $enabled, ?string $locale = null): User
    {
        $user = $this->security->currentUser('You must be signed in to update notification settings.');
        $user->setEmailNotifications($enabled);
        // Pin the locale at opt-in — the digest worker has no request locale.
        if (null !== $locale && '' !== $locale) {
            $user->setLocale($locale);
        }

        return $this->save($user);
    }

    #[\Override]
    public function markOnboardedForCurrentUser(): User
    {
        $user = $this->security->currentUser('You must be signed in to complete onboarding.');
        if (null === $user->getOnboardedAt()) {
            $user->markOnboarded();
            $this->save($user);
        }

        return $user;
    }
}
