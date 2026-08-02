<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Dto\User\PasswordResetDto;
use App\Dto\User\RequestPasswordResetDto;
use App\Entity\User;

interface ResetPasswordRequestServiceInterface
{
    public function requestPasswordReset(RequestPasswordResetDto $dto): void;

    public function resetPassword(PasswordResetDto $passwordResetDto): void;

    public function isValid(string $email, string $token): bool;

    /** Admin-initiated only — bypasses the public path's enumeration/rate-limit guards. */
    public function sendInvitation(User $user): void;
}
