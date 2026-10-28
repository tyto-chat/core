<?php

declare(strict_types=1);

namespace App\Dto\Conversation;

final readonly class ConversationPreflightResult
{
    public function __construct(
        public PolicyDecision $decision,
        public ?string $existingIdentifier,
    ) {
    }
}
