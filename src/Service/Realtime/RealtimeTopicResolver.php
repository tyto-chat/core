<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\Conversation;
use App\Entity\User;
use App\Enum\Channel\ChannelType;
use App\Exception\DomainExceptionInterface;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Conversation\ConversationServiceInterface;
use App\Utils\Topics;

final readonly class RealtimeTopicResolver
{
    public function __construct(
        private ChannelServiceInterface $channelService,
        private CommunityServiceInterface $communityService,
        private ConversationServiceInterface $conversationService,
    ) {
    }

    /** @return string[] */
    public function forUser(User $user, ?string $communityIdentifier, ?string $conversationIdentifier): array
    {
        $topics = [
            Topics::userNotifications($user->getId()),
            Topics::userEvents($user->getId()),
            Topics::userConversationActivity($user->getId()),
            Topics::presenceTemplate(),
        ];

        $community = $this->findCommunity($communityIdentifier);
        if (null !== $community) {
            $communityId = $community->getId();
            $topics[] = Topics::community($communityId);
            $topics[] = Topics::communityEmojis($communityId);
            $topics[] = Topics::communityPresence($communityId);
            foreach ($this->channelService->getViewableInCommunity($community) as $channel) {
                array_push($topics, ...$this->channelTopics($channel));
                $topics[] = Topics::channelActivity((int) $channel->getId());
                if (ChannelType::Audio === $channel->getType()) {
                    $topics[] = Topics::channelParticipants((int) $channel->getId());
                }
            }
        }

        $conversation = $this->findConversation($conversationIdentifier);
        if (null !== $conversation) {
            $topics[] = Topics::conversation((int) $conversation->getId());
            $topics[] = Topics::conversationThreads((int) $conversation->getId());
        }

        return $topics;
    }

    /** @return string[] */
    public function forAnonymous(?string $communityIdentifier): array
    {
        $community = $this->findCommunity($communityIdentifier);
        if (null === $community) {
            return [];
        }

        $topics = [];
        foreach ($this->channelService->getViewableTextChannels($community) as $channel) {
            array_push($topics, ...$this->channelTopics($channel));
        }
        if ([] === $topics) {
            return [];
        }

        $topics[] = Topics::community($community->getId());
        $topics[] = Topics::communityEmojis($community->getId());

        return $topics;
    }

    /** @return string[] */
    private function channelTopics(Channel $channel): array
    {
        $channelId = (int) $channel->getId();

        return [Topics::channel($channelId), Topics::channelThreads($channelId)];
    }

    private function findCommunity(?string $identifier): ?Community
    {
        if (null === $identifier || '' === $identifier) {
            return null;
        }
        try {
            return $this->communityService->getByIdentifier($identifier);
        } catch (DomainExceptionInterface) {
            return null;
        }
    }

    private function findConversation(?string $identifier): ?Conversation
    {
        if (null === $identifier || '' === $identifier) {
            return null;
        }
        try {
            return $this->conversationService->getByIdentifier($identifier);
        } catch (DomainExceptionInterface) {
            return null;
        }
    }
}
