<?php

declare(strict_types=1);

namespace App\Dto\Notification;

use App\Enum\Channel\ChannelNotificationLevel;
use App\Enum\Channel\ChannelPinState;
use Symfony\Component\Serializer\Attribute\Groups;

final readonly class ChannelLevelDto
{
    public function __construct(
        #[Groups(['notification_preferences:read'])]
        public int $channelId = 0,
        #[Groups(['notification_preferences:read'])]
        public ChannelNotificationLevel $level = ChannelNotificationLevel::Mentions,
        #[Groups(['notification_preferences:read'])]
        public ?ChannelPinState $pinState = null,
    ) {
    }
}
