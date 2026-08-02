<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Dto\Profile\UpdateProfileDto;
use App\Entity\MediaObject;
use App\Entity\Profile;

interface ProfileServiceInterface
{
    public function get(int $id): Profile;

    public function update(Profile $profile, UpdateProfileDto $updateProfileDto): Profile;

    public function findByAvatar(MediaObject $avatar): ?Profile;
}
