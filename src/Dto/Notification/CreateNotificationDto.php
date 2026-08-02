<?php

declare(strict_types=1);

namespace App\Dto\Notification;

use App\Dto\EntityDtoInterface;
use App\Entity\Community;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\Notification\NotificationType;

readonly class CreateNotificationDto implements EntityDtoInterface
{
    public function __construct(
        public readonly User $recipient,
        public readonly ?Community $community = null,
        public readonly string $communityIdentifier = '',
        public readonly string $channelIdentifier = '',
        public readonly NotificationType $type = NotificationType::Mention,
        public readonly string $authorName = '',
        public readonly ?string $messageIri = null,
        public readonly string $groupName = '',
        public readonly string $groupIdentifier = '',
        public readonly ?string $reason = null,
        public readonly ?\DateTimeInterface $expiresAt = null,
        public readonly ?string $conversationIdentifier = null,
        public readonly ?int $moderationActionId = null,
    ) {
    }

    public static function getEntityClass(): string
    {
        return Notification::class;
    }

    #[\Override]
    public function applyTo(object $entity): void
    {
        assert($entity instanceof Notification);
        $entity->setRecipient($this->recipient);
        $entity->setCommunity($this->community);
        $entity->setCommunityIdentifier($this->communityIdentifier);
        $entity->setChannelIdentifier($this->channelIdentifier);
        $entity->setConversationIdentifier($this->conversationIdentifier);
        $entity->setType($this->type);
        $entity->setAuthorName($this->authorName);
        $entity->setMessageIri($this->messageIri);
        $entity->setGroupName($this->groupName);
        $entity->setGroupIdentifier($this->groupIdentifier);
        $entity->setReason($this->reason);
        $entity->setExpiresAt($this->expiresAt);
        $entity->setModerationActionId($this->moderationActionId);
    }
}
