<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use ApiPlatform\Metadata\IriConverterInterface;
use App\Entity\Channel;
use App\Entity\ChannelParticipant;
use App\Entity\Community;
use App\Entity\Conversation;
use App\Entity\MediaObject;
use App\Entity\Message;
use App\Entity\Notification;
use App\Enum\Presence\PresenceState;
use App\Service\MediaObject\SignedUrlServiceInterface;
use App\Service\Voice\ChannelParticipantStoreInterface;
use App\Utils\Topics;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class MercurePublisher implements RealtimePublisherInterface
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly IriConverterInterface $iriConverter,
        private readonly ChannelParticipantStoreInterface $participantStore,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly SignedUrlServiceInterface $signedUrlService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Always private — a public publish bypasses JWT subscribe claims and leaks private data; every publish must go through here.
     *
     * Never rethrows a hub failure: async handlers flush before publishing, so messenger retries would duplicate their rows.
     *
     * @param string|list<string>  $topic
     * @param array<string, mixed> $data
     */
    private function publish(string|array $topic, array $data): void
    {
        $payload = json_encode($data, \JSON_THROW_ON_ERROR);

        try {
            $this->hub->publish(new Update($topic, $payload, private: true));
        } catch (\Throwable $e) {
            $this->logger->error('Mercure publish failed: {reason}', [
                'reason' => $e->getMessage(),
                'topic' => $topic,
                'exception' => $e,
            ]);
        }
    }

    public function publishChannelActivity(Channel $channel): void
    {
        $communityId = $channel->getCommunity()?->getId();
        if (null === $communityId) {
            return;
        }

        $this->publish(
            [Topics::communityActivity($communityId), Topics::channelActivity((int) $channel->getId())],
            [
                'type' => 'channel.activity',
                'channelIdentifier' => $channel->getIdentifier(),
                'lastMessageAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ],
        );
    }

    public function publishChannelTyping(Channel $channel, int $userId, string $name): void
    {
        $channelId = $channel->getId();
        if (null === $channelId) {
            return;
        }

        $this->publishTyping(Topics::channel($channelId), $userId, $name);
    }

    public function publishConversationTyping(Conversation $conversation, int $userId, string $name): void
    {
        $topic = $conversation->getTopic();
        if ('' === $topic) {
            return;
        }

        $this->publishTyping($topic, $userId, $name);
    }

    private function publishTyping(string $topic, int $userId, string $name): void
    {
        $this->publish($topic, [
            'type' => 'typing',
            'userId' => $userId,
            'name' => $name,
        ]);
    }

    public function publishMessageUpdated(Message $message, string $body): void
    {
        $topic = $message->getPublishTopic();
        if ('' === $topic) {
            return;
        }

        $this->publish($topic, [
            'type' => 'message.update',
            '@id' => $this->iriConverter->getIriFromResource($message),
            'text' => $body,
            'edited' => true,
        ]);
    }

    public function publishMessageDeleted(Message $message): void
    {
        $topic = $message->getPublishTopic();
        if ('' === $topic) {
            return;
        }

        $this->publish($topic, [
            'type' => 'message.update',
            '@id' => $this->iriConverter->getIriFromResource($message),
            'isDeleted' => true,
            'text' => null,
        ]);
    }

    public function publishMessagePinned(Message $message): void
    {
        $topic = $message->getContainerTopic();
        if ('' === $topic) {
            return;
        }

        $this->publish($topic, [
            'type' => 'message.update',
            '@id' => $this->iriConverter->getIriFromResource($message),
            'pinned' => $message->isPinned(),
            'pinnedAt' => $message->getPinnedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    public function publishMessageThreadMeta(Message $root): void
    {
        $topic = $root->getContainerTopic();
        if ('' === $topic) {
            return;
        }

        $this->publish($topic, [
            'type' => 'message.update',
            '@id' => $this->iriConverter->getIriFromResource($root),
            'replyCount' => $root->getReplyCount(),
            'lastReplyAt' => $root->getLastReplyAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    public function publishMessageReactions(Message $message): void
    {
        $topic = $message->getPublishTopic();
        if ('' === $topic) {
            return;
        }

        $this->publish($topic, [
            'type' => 'message.update',
            '@id' => $this->iriConverter->getIriFromResource($message),
            'reactions' => $message->getReactions(),
        ]);
    }

    public function publishNotification(Notification $notification): void
    {
        $topic = $notification->getRecipientNotificationTopic();
        if ('' === $topic) {
            return;
        }

        $this->publish($topic, $this->notificationPayload($notification, 'notification'));
    }

    public function publishNotificationUpdated(Notification $notification): void
    {
        $topic = $notification->getRecipientNotificationTopic();
        if ('' === $topic) {
            return;
        }

        $this->publish($topic, $this->notificationPayload($notification, 'notification.update'));
    }

    /** @return array<string, mixed> */
    private function notificationPayload(Notification $notification, string $type): array
    {
        return [
            'type' => $type,
            'id' => $notification->getId(),
            'notificationType' => $notification->getType()->value,
            'isRead' => $notification->getIsRead(),
            'communityId' => $notification->getCommunity()?->getId(),
            'communityIdentifier' => $notification->getCommunityIdentifier(),
            'channelIdentifier' => $notification->getChannelIdentifier(),
            'conversationIdentifier' => $notification->getConversationIdentifier(),
            'messageIri' => $notification->getMessageIri(),
            'authorName' => $notification->getAuthorName(),
            'reason' => $notification->getReason(),
            'groupName' => $notification->getGroupName(),
            'groupIdentifier' => $notification->getGroupIdentifier(),
            'actorIds' => $notification->getActorIds(),
            'messageCount' => $notification->getMessageCount(),
            'createdAt' => $notification->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    public function publishAudioChannelParticipants(Channel $channel): void
    {
        $channelId = $channel->getId();
        if (null === $channelId) {
            return;
        }

        $participants = $this->participantStore->findByChannel($channel);
        $generatedAt = microtime(true);

        $data = array_map(function (ChannelParticipant $p): array {
            $profile = $p->getProfile();

            return [
                'userId' => $p->getUserId(),
                'name' => $profile?->getName(),
                'avatarUrl' => $this->resolveAvatarUrl($profile?->getAvatar()),
                'joinedAt' => $p->getJoinedAt()->format(\DateTimeInterface::ATOM),
            ];
        }, $participants);

        $this->publish(
            Topics::channelParticipants($channelId),
            [
                'type' => 'channel.participants',
                'channelId' => $channelId,
                'participants' => $data,
                'generatedAt' => $generatedAt,
            ],
        );
    }

    public function publishMessageAttachmentsUpdated(Message $message): void
    {
        $topic = $message->getPublishTopic();
        if ('' === $topic) {
            return;
        }

        $attachments = array_values(array_map(
            fn (MediaObject $a): array => [
                '@id' => $this->iriConverter->getIriFromResource($a),
                'originalName' => $a->originalName,
                'mimeType' => $a->mimeType,
                'size' => $a->size,
                'contentUrl' => $a->filePath
                    ? $this->urlGenerator->generate(
                        'app_media_serve',
                        [
                            'token' => $this->signedUrlService->sign($a->filePath),
                            'filename' => $a->filePath,
                        ],
                        UrlGeneratorInterface::ABSOLUTE_URL,
                    )
                    : null,
            ],
            $message->getAttachments()->toArray()
        ));

        $this->publish($topic, [
            'type' => 'message.update',
            '@id' => $this->iriConverter->getIriFromResource($message),
            'attachments' => $attachments,
        ]);
    }

    public function publishCommunityEmojisUpdated(Community $community): void
    {
        $this->publish(
            Topics::communityEmojis($community->getId()),
            [
                'type' => 'community.emojis.updated',
                'communityIdentifier' => $community->getIdentifier(),
            ],
        );
    }

    public function publishCommunityStructureChanged(Community $community, bool $grantsChanged = false): void
    {
        $data = [
            'type' => 'community.structure',
            'communityIdentifier' => $community->getIdentifier(),
        ];
        if ($grantsChanged) {
            $data['grantsChanged'] = true;
        }

        $this->publish(Topics::community($community->getId()), $data);
    }

    public function publishUserEvent(int $userId, string $event, array $payload = []): void
    {
        $this->publish(Topics::userEvents($userId), array_merge([
            'type' => 'user.event',
            'event' => $event,
        ], $payload));
    }

    public function publishConversationActivity(Conversation $conversation): void
    {
        $data = [
            'type' => 'conversation.activity',
            'conversationIdentifier' => $conversation->getIdentifier(),
            'lastMessageAt' => $conversation->getLastMessageAt()?->format(\DateTimeInterface::ATOM),
        ];

        foreach ($conversation->getMembers() as $member) {
            $userId = $member->getUser()->getId();
            if (null === $userId) {
                continue;
            }
            $this->publish(Topics::userConversationActivity($userId), $data);
        }
    }

    public function publishPresenceChanged(int $userId, PresenceState $state, array $communityIds = []): void
    {
        $data = [
            'type' => 'presence',
            'userId' => $userId,
            'state' => $state->value,
        ];

        // One Update per topic: the hub delivers a private Update if any of its topics is granted.
        foreach ([Topics::userPresence($userId), ...array_map(Topics::communityPresence(...), $communityIds)] as $topic) {
            $this->publish($topic, $data);
        }
    }

    private function resolveAvatarUrl(?MediaObject $avatar): ?string
    {
        if (!$avatar || !$avatar->filePath) {
            return null;
        }

        $relativePath = 'avatar_md/'.$avatar->filePath;

        return $this->urlGenerator->generate(
            'app_media_serve_variant',
            [
                'token' => $this->signedUrlService->sign($relativePath),
                'filter' => 'avatar_md',
                'filename' => $avatar->filePath,
            ],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
