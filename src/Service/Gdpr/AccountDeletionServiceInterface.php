<?php

declare(strict_types=1);

namespace App\Service\Gdpr;

use App\Entity\User;

interface AccountDeletionServiceInterface
{
    public const int GRACE_PERIOD_DAYS = 7;

    /**
     * @throws \App\Exception\User\AccountAlreadyPendingDeletionException
     */
    public function requestDeletion(): User;

    /**
     * @throws \App\Exception\User\AccountNotPendingDeletionException
     */
    public function cancelDeletion(): User;

    public function findPurgeDateForCurrentUser(): ?\DateTimeImmutable;

    public function purgeExpired(): int;

    public function purgeForUser(User $user): void;
}
