<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Dto\User\BlockRelations;
use App\Entity\User;
use App\Entity\UserBlock;

interface UserBlockServiceInterface
{
    public function block(int $targetUserId): UserBlock;

    public function unblock(int $targetUserId): void;

    /** @return list<array{block: UserBlock, inEffect: bool}> newest first */
    public function listForCurrentUser(): array;

    /** @return array{blocked: bool, blockable: bool, inEffect: bool} */
    public function statusForCurrentUser(int $targetUserId): array;

    public function isStaffFor(User $subject, User $viewer): bool;

    /** @param list<int> $otherIds */
    public function enforcedRelations(User $user, array $otherIds): BlockRelations;

    /**
     * @param list<int> $recipientIds
     *
     * @return list<int> recipients that do not enforce a block against the actor
     */
    public function filterRecipientsNotBlocking(int $actorId, array $recipientIds): array;

    /**
     * @param list<int> $blockerIds
     *
     * @return array<int, list<int>> blockerId → enforced blocked ids
     */
    public function enforcedBlockedIdsByBlocker(array $blockerIds): array;

    public function deleteAllInvolving(User $user): void;
}
