<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Dto\UserPreference\UpdateUserPreferencesDto;
use App\Entity\User;
use App\Entity\UserPreference;
use App\Enum\Settings\EncryptedDmsMode;
use App\Exception\E2ee\EncryptedDmsDisabledException;
use App\Repository\UserPreferenceRepository;
use App\Security\SecurityContext;
use App\Service\AbstractDoctrineService;
use App\Service\E2ee\E2eeServiceInterface;

class UserPreferenceService extends AbstractDoctrineService implements UserPreferenceServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly UserPreferenceRepository $repository,
        private readonly E2eeServiceInterface $e2ee,
    ) {
    }

    #[\Override]
    public function getForCurrentUser(): UserPreference
    {
        $user = $this->security->currentUser('You must be signed in to access preferences.');

        return $this->getForUser($user);
    }

    #[\Override]
    public function getForUser(User $user): UserPreference
    {
        // GET must not write — the row is first persisted by update().
        return $this->repository->findForUser($user) ?? new UserPreference($user);
    }

    #[\Override]
    public function update(UserPreference $pref, UpdateUserPreferencesDto $dto): UserPreference
    {
        $caller = $this->security->currentUser('You must be signed in to update preferences.');
        $this->security->throwAccessDeniedIf($pref->getUser()->getId() !== $caller->getId(), 'You can only update your own preferences.');
        $this->assertRefusalAllowed($pref, $dto);

        $dto->applyTo($pref);
        $pref->touch();

        return $this->save($pref);
    }

    private function assertRefusalAllowed(UserPreference $pref, UpdateUserPreferencesDto $dto): void
    {
        if ($dto->turnsOnPlainDmRefusal() && !$pref->refusesPlainDms() && EncryptedDmsMode::Disabled === $this->e2ee->mode()) {
            throw new EncryptedDmsDisabledException();
        }
    }
}
