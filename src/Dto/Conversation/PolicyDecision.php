<?php

declare(strict_types=1);

namespace App\Dto\Conversation;

use App\Enum\Conversation\PolicyOutcome;
use App\Enum\Conversation\PolicyReason;

final readonly class PolicyDecision
{
    /**
     * @param list<int> $refusingUserIds
     * @param list<int> $unreachableUserIds
     */
    public function __construct(
        public PolicyOutcome $outcome,
        public bool $mustEncrypt,
        public ?PolicyReason $reason,
        public array $refusingUserIds,
        public array $unreachableUserIds,
    ) {
    }

    public function isEncrypted(): bool
    {
        return PolicyOutcome::Encrypted === $this->outcome;
    }
}
