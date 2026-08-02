<?php

declare(strict_types=1);

namespace App\Dto\Channel;

use App\Enum\Channel\ChannelRole;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class AddChannelMemberDto
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Positive]
        #[Groups(['channel_member:add'])]
        public int $userId = 0,
        #[Groups(['channel_member:add'])]
        public ChannelRole $role = ChannelRole::Member,
    ) {
    }
}
