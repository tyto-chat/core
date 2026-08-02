<?php

declare(strict_types=1);

namespace App\Dto\Notification;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

// Nullable + NotNull: an absent "muted" is a 422, not a default.
final readonly class SetCommunityMuteDto
{
    public function __construct(
        #[Assert\NotNull]
        #[Groups(['community_mute:read', 'community_mute:write'])]
        public ?bool $muted = null,
    ) {
    }
}
