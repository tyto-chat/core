<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\Notification;
use App\Enum\Presence\PresenceState;

interface RealtimePublisherInterface extends MessageRealtimePublisherInterface, StructureRealtimePublisherInterface
{
    public function publishNotification(Notification $notification): void;

    public function publishNotificationUpdated(Notification $notification): void;

    public function publishAudioChannelParticipants(Channel $channel): void;

    public function publishPresenceChanged(int $userId, PresenceState $state): void;

    public function publishChannelTyping(Channel $channel, int $userId, string $name): void;

    public function publishConversationTyping(Conversation $conversation, int $userId, string $name): void;
}
