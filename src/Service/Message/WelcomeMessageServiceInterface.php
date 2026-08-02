<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Entity\Community;
use App\Entity\User;

interface WelcomeMessageServiceInterface
{
    /** All failure modes are silent — joining must succeed regardless of welcome plumbing. */
    public function sendIfConfigured(Community $community, User $newMember): void;
}
