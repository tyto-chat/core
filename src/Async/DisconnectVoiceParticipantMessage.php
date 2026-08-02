<?php

declare(strict_types=1);

namespace App\Async;

final class DisconnectVoiceParticipantMessage
{
    public function __construct(
        public readonly int $userId,
        public readonly ?int $channelId = null,
        public readonly ?string $communityIdentifier = null,
    ) {
    }
}
