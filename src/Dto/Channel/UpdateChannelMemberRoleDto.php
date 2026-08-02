<?php

declare(strict_types=1);

namespace App\Dto\Channel;

use App\Enum\Channel\ChannelRole;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateChannelMemberRoleDto
{
    public function __construct(
        // Nullable + NotNull, not a Member default: an empty PATCH must 422, not silently demote a moderator.
        #[Assert\NotNull]
        public ?ChannelRole $role = null,
    ) {
    }
}
