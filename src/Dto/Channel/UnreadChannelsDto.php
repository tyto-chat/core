<?php

declare(strict_types=1);

namespace App\Dto\Channel;

use Symfony\Component\Serializer\Attribute\Groups;

final readonly class UnreadChannelsDto
{
    /** @param string[] $unread */
    public function __construct(
        #[Groups(['channel_unread:read'])]
        public array $unread = [],
    ) {
    }
}
