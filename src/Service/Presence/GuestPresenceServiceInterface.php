<?php

declare(strict_types=1);

namespace App\Service\Presence;

use App\Entity\Community;

interface GuestPresenceServiceInterface
{
    public function touch(Community $community, string $visitorKey): void;

    public function getGuestCount(Community $community): int;
}
