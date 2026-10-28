<?php

declare(strict_types=1);

namespace App\Security;

use App\Dto\Conversation\PolicyDecision;
use App\Entity\User;

interface ConversationPolicyInterface
{
    /**
     * @param list<User> $others
     */
    public function decide(User $creator, array $others): PolicyDecision;
}
