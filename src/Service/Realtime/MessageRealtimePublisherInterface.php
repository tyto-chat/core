<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\Message;

interface MessageRealtimePublisherInterface
{
    public function publishChannelActivity(Channel $channel): void;

    public function publishMessageUpdated(Message $message, string $body): void;

    public function publishMessageDeleted(Message $message): void;

    public function publishMessageReactions(Message $message): void;

    public function publishMessageAttachmentsUpdated(Message $message): void;

    public function publishConversationActivity(Conversation $conversation): void;

    public function publishMessagePinned(Message $message): void;

    public function publishMessageThreadMeta(Message $root): void;
}
