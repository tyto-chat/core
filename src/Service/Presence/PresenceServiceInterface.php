<?php

declare(strict_types=1);

namespace App\Service\Presence;

use App\Dto\Presence\PresenceSnapshot;
use App\Entity\Community;
use App\Entity\User;
use App\Enum\Presence\ManualStatus;

interface PresenceServiceInterface
{
    public function getCommunityOnlineCount(Community $community): int;

    /**
     * @return list<PresenceSnapshot>
     */
    public function getCommunityOnline(Community $community): array;

    public function touch(User $user): void;

    public function setManualStatus(User $user, ?ManualStatus $status): void;

    public function offline(User $user): void;

    public function reevaluate(User $user): void;

    public function get(User $user): PresenceSnapshot;

    /**
     * @param int[] $userIds
     *
     * @return array<int, PresenceSnapshot>
     */
    public function getBatch(array $userIds): array;
}
