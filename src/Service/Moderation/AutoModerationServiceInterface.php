<?php

declare(strict_types=1);

namespace App\Service\Moderation;

use App\Entity\Community;
use App\Entity\User;

interface AutoModerationServiceInterface
{
    public function countBotTimeouts(Community $community, User $user): int;

    public function computeAutoTimeoutDuration(Community $community, User $user): \DateTimeImmutable;
}
