<?php

declare(strict_types=1);

namespace App\Service\Message;

use ApiPlatform\Metadata\IriConverterInterface;
use App\Dto\Notification\CreateNotificationDto;
use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use App\Enum\Notification\NotificationType;
use App\Enum\Presence\PresenceState;
use App\Service\Channel\ChannelMembershipServiceInterface;
use App\Service\Channel\ChannelReadStateServiceInterface;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Notification\ChannelUserPreferenceService;
use App\Service\Notification\ChannelUserPreferenceServiceInterface;
use App\Service\Notification\NotificationServiceInterface;
use App\Service\Presence\PresenceServiceInterface;
use App\Service\User\UserServiceInterface;
use App\Utils\MentionExtractor;
use Symfony\Component\DependencyInjection\Attribute\Lazy;

class MessageNotificationDispatcher implements MessageNotificationDispatcherInterface
{
    public function __construct(
        #[Lazy]
        private readonly MessageServiceInterface $messageService,
        private readonly NotificationServiceInterface $notificationService,
        private readonly ChannelUserPreferenceServiceInterface $channelPreferenceService,
        private readonly UserServiceInterface $userService,
        private readonly ChannelMembershipServiceInterface $channelMembershipService,
        private readonly ChannelReadStateServiceInterface $channelReadStateService,
        private readonly CommunityMembershipServiceInterface $communityMembershipService,
        #[Lazy]
        private readonly PresenceServiceInterface $presenceService,
        private readonly IriConverterInterface $iriConverter,
        private readonly int $activeChannelWindowSeconds = 60,
    ) {
    }

    #[\Override]
    public function dispatchFor(Message $message): void
    {
        $this->messageService->hydrateText($message);
        $this->dispatchMentionNotifications($message);
    }

    #[\Override]
    public function dispatchForDm(Message $message, Conversation $conversation, User $author): void
    {
        $authorName = $message->getCreatedBy()?->getProfile()?->getName() ?? 'Unknown';
        $messageIri = $this->iriConverter->getIriFromResource($message);
        $authorId = $author->getId();

        foreach ($conversation->getMembers() as $member) {
            $recipient = $member->getUser();
            if ($recipient->getId() === $authorId) {
                continue;
            }
            if ($member->isMuted()) {
                continue;
            }

            $this->notificationService->new(new CreateNotificationDto(
                recipient: $recipient,
                type: NotificationType::DmMessage,
                authorName: $authorName,
                messageIri: $messageIri,
                conversationIdentifier: $conversation->getIdentifier(),
            ));
        }
    }

    private function dispatchMentionNotifications(Message $message): void
    {
        $channel = $message->getChannel();
        if (null === $channel) {
            return;
        }
        $authorId = $message->getCreatedBy()?->getId();
        $community = $channel->getCommunity();
        $authorName = $message->getCreatedBy()?->getProfile()?->getName() ?? 'Unknown';
        $messageIri = $this->iriConverter->getIriFromResource($message);
        $communityIdentifier = $community?->getIdentifier() ?? '';

        $directIds = array_values(array_filter(
            $message->getMentionedUserIds(),
            static fn (int $id): bool => $id !== $authorId,
        ));
        $directIds = $this->channelPreferenceService->filterRecipients(
            $channel,
            $directIds,
            ChannelUserPreferenceService::CATEGORY_MENTION,
        );
        foreach ($this->userService->findByIds($directIds) as $recipient) {
            $this->notificationService->new(new CreateNotificationDto(
                recipient: $recipient,
                community: $community,
                channelIdentifier: $channel->getIdentifier(),
                communityIdentifier: $communityIdentifier,
                authorName: $authorName,
                messageIri: $messageIri,
            ));
        }

        $broadcastIds = [];
        $broadcasts = MentionExtractor::extractBroadcasts($message->getText() ?? '');
        if ([] !== $broadcasts) {
            $exclude = $directIds;
            if (null !== $authorId) {
                $exclude[] = $authorId;
            }
            $broadcastIds = array_values(array_diff($this->broadcastRecipientIds($channel, $broadcasts), $exclude));
            $broadcastIds = $this->channelPreferenceService->filterRecipients(
                $channel,
                $broadcastIds,
                ChannelUserPreferenceService::CATEGORY_MENTION,
            );
            foreach ($this->userService->findByIds($broadcastIds) as $recipient) {
                $this->notificationService->new(new CreateNotificationDto(
                    recipient: $recipient,
                    community: $community,
                    channelIdentifier: $channel->getIdentifier(),
                    communityIdentifier: $communityIdentifier,
                    type: NotificationType::BroadcastMention,
                    authorName: $authorName,
                    messageIri: $messageIri,
                ));
            }
        }

        $this->dispatchPlainMessageNotifications($message, $directIds, $broadcastIds);
    }

    /**
     * @param int[] $directIds
     * @param int[] $broadcastIds
     */
    private function dispatchPlainMessageNotifications(Message $message, array $directIds, array $broadcastIds): void
    {
        $channel = $message->getChannel();
        if (null === $channel) {
            return;
        }
        $author = $message->getCreatedBy();
        $authorId = $author?->getId();
        if (null === $authorId) {
            return;
        }
        $community = $channel->getCommunity();
        if (null === $community) {
            return;
        }

        $candidates = $channel->isPrivate()
            ? $this->channelMembershipService->getMemberUserIds($channel)
            : array_map('intval', $this->communityMembershipService->findMemberUserIds($community));
        $exclude = array_unique(array_merge([$authorId], $directIds, $broadcastIds));
        $candidates = array_values(array_diff($candidates, $exclude));

        $recipientIds = $this->channelPreferenceService->filterRecipients(
            $channel,
            $candidates,
            ChannelUserPreferenceService::CATEGORY_PLAIN,
        );
        if ([] === $recipientIds) {
            return;
        }

        $authorName = $message->getCreatedBy()?->getProfile()?->getName() ?? 'Unknown';
        $messageIri = $this->iriConverter->getIriFromResource($message);
        $cutoff = new \DateTimeImmutable('-'.$this->activeChannelWindowSeconds.' seconds');
        $lastReadByUserId = $this->channelReadStateService->findLastReadAtForUsers($channel, $recipientIds);

        foreach ($this->userService->findByIds($recipientIds) as $recipient) {
            $lastReadAt = $lastReadByUserId[(int) $recipient->getId()] ?? null;
            if (null !== $lastReadAt && $lastReadAt >= $cutoff) {
                continue;
            }

            $this->notificationService->upsertChannelActivity(
                $recipient,
                $channel,
                $authorId,
                $authorName,
                $messageIri,
            );
        }
    }

    /**
     * @param string[] $broadcasts
     *
     * @return int[]
     */
    private function broadcastRecipientIds(Channel $channel, array $broadcasts): array
    {
        $community = $channel->getCommunity();
        if (null === $community) {
            return [];
        }

        $audience = $channel->isPrivate()
            ? $this->channelMembershipService->getMemberUserIds($channel)
            : array_map('intval', $this->communityMembershipService->findMemberUserIds($community));
        $audience = array_values(array_unique($audience));

        $recipients = [];
        if (in_array('channel', $broadcasts, true)) {
            $recipients = $audience;
        }
        if (in_array('here', $broadcasts, true)) {
            // getBatch, not getCommunityOnline — that one is voter-gated and this runs in the token-less async worker.
            $onlineIds = [];
            foreach ($this->presenceService->getBatch($audience) as $snapshot) {
                if (PresenceState::Online === $snapshot->state) {
                    $onlineIds[] = $snapshot->userId;
                }
            }
            $recipients = array_merge($recipients, $onlineIds);
        }

        return array_values(array_unique($recipients));
    }
}
