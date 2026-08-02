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
    ) {
    }

    /**
     * Always private — a public publish bypasses JWT subscribe claims and leaks private data; every publish must go through here.
     *
     * @param array<string, mixed> $data
     */
    private function publish(string $topic, array $data): void
    {
        $this->hub->publish(new Update(
            $topic,
            json_encode($data, \JSON_THROW_ON_ERROR),
            private: true,
        ));
    }

    /** IriConverter IRIs are versioned in-request but topics must stay unversioned — strip here; payload '@id' stays versioned. */
    private function topic(string $iri): string
    {
        return preg_replace('#^/api/v\d+/#', '/api/', $iri) ?? $iri;
    }

    public function publishChannelActivity(Channel $channel): void
    {
        $community = $channel->getCommunity();
        if (null === $community) {
            return;
        }

        $this->publish(
            $this->topic($this->iriConverter->getIriFromResource($community)).'/activity',
            [
                'type' => 'channel.activity',
                'channelIdentifier' => $channel->getIdentifier(),
                'lastMessageAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ],
        );
    }

    public function publishChannelTyping(Channel $channel, int $userId, string $name): void
    {
        $topic = Topics::channel($channel->getCommunity()?->getIdentifier(), $channel->getIdentifier());
        if (Topics::channel(null, null) === $topic) {
            return;
        }

        $this->publishTyping($topic, $userId, $name);
    }

    public function publishConversationTyping(Conversation $conversation, int $userId, string $name): void
    {
        $this->publishTyping($conversation->getConversationIri(), $userId, $name);
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
        $topic = $message->getContainerIri();
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
        $topic = $root->getContainerIri();
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
        $this->publish(
            $this->topic($this->iriConverter->getIriFromResource($notification->getRecipient())).'/notifications',
            $this->notificationPayload($notification, 'notification'),
        );
    }

    public function publishNotificationUpdated(Notification $notification): void
    {
        $this->publish(
            $this->topic($this->iriConverter->getIriFromResource($notification->getRecipient())).'/notifications',
            $this->notificationPayload($notification, 'notification.update'),
        );
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

        $community = $channel->getCommunity();
        $this->publish(
            Topics::channelParticipants($community?->getIdentifier(), $channel->getIdentifier()),
            [
                'type' => 'channel.participants',
                'channelId' => $channel->getId(),
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
            $this->topic($this->iriConverter->getIriFromResource($community)).'/emojis',
            [
                'type' => 'community.emojis.updated',
                'communityIdentifier' => $community->getIdentifier(),
            ],
        );
    }

    public function publishCommunityStructureChanged(Community $community): void
    {
        $this->publish(
            $this->topic($this->iriConverter->getIriFromResource($community)),
            [
                'type' => 'community.structure',
                'communityIdentifier' => $community->getIdentifier(),
            ],
        );
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

    public function publishPresenceChanged(int $userId, PresenceState $state): void
    {
        $this->publish(Topics::userPresence($userId), [
            'type' => 'presence',
            'userId' => $userId,
            'state' => $state->value,
        ]);
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
