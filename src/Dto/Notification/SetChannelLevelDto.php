<?php

declare(strict_types=1);

namespace App\Dto\Notification;

use App\Enum\Channel\ChannelNotificationLevel;
use Symfony\Component\Serializer\Attribute\Groups;

/** Input + echo for the per-channel notification level setter; null clears the override. */
final readonly class SetChannelLevelDto
{
    public function __construct(
        #[Groups(['channel_notification_level:read', 'channel_notification_level:write'])]
        public ?ChannelNotificationLevel $level = null,
    ) {
    }
}
