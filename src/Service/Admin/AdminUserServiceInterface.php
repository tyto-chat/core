<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\Admin\AdminUserDetailDto;
use App\Dto\Admin\AdminUserPatchDto;
use App\Entity\User;

interface AdminUserServiceInterface
{
    /**
     * @param array<int> $communityIds ignored for bots
     */
    public function provision(string $name, ?string $email, bool $isBot, array $communityIds): User;

    public function patch(int $id, AdminUserPatchDto $dto): User;

    public function forceDelete(int $id, ?string $confirm): void;

    public function detail(User $user): AdminUserDetailDto;

    public function disableTwoFactor(int $id): User;
}
