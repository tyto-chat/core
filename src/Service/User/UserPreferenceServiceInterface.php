<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Dto\UserPreference\UpdateUserPreferencesDto;
use App\Entity\User;
use App\Entity\UserPreference;

interface UserPreferenceServiceInterface
{
    public function getForCurrentUser(): UserPreference;

    public function getForUser(User $user): UserPreference;

    public function update(UserPreference $pref, UpdateUserPreferencesDto $dto): UserPreference;
}
