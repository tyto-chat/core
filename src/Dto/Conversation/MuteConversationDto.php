<?php

declare(strict_types=1);

namespace App\Dto\Conversation;

use Symfony\Component\Serializer\Attribute\Groups;

final readonly class MuteConversationDto
{
    public function __construct(
        #[Groups(['conversation:mute'])]
        public ?\DateTimeImmutable $mutedUntil = null,
    ) {
    }
}
