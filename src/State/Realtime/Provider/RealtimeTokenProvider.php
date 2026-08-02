<?php

declare(strict_types=1);

namespace App\State\Realtime\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Realtime\RealtimeTokenDto;
use App\Entity\Channel;
use App\Entity\User;
use App\Enum\Channel\ChannelType;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\Realtime\MercureSubscriberTokenFactory;
use App\Utils\Topics;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @implements ProviderInterface<RealtimeTokenDto>
 */
final readonly class RealtimeTokenProvider implements ProviderInterface
{
    public function __construct(
        private MercureSubscriberTokenFactory $tokenFactory,
        private ChannelServiceInterface $channelService,
        private CommunityServiceInterface $communityService,
        private ConversationServiceInterface $conversationService,
        private Security $security,
        #[Autowire(param: 'lexik_jwt_authentication.token_ttl')]
        private int $tokenTtl,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): RealtimeTokenDto
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new RealtimeTokenDto();
        }

        $viewableChannels = $this->channelService->getViewableWithCommunity();

        $topics = array_map(
            static fn (Channel $c): string => Topics::channel($c->getCommunity()->getIdentifier(), $c->getIdentifier()),
            $viewableChannels
        );

        // getAll() = public + joined only — must never grant a private community the caller isn't in
        foreach ($this->communityService->getAll() as $community) {
            $topics[] = Topics::communityActivity($community->getIdentifier());
            $topics[] = Topics::community($community->getIdentifier());
            $topics[] = Topics::communityEmojis($community->getIdentifier());
        }

        foreach ($viewableChannels as $channel) {
            if (ChannelType::Audio === $channel->getType()) {
                $topics[] = Topics::channelParticipants($channel->getCommunity()?->getIdentifier(), $channel->getIdentifier());
            }
        }

        $topics[] = Topics::userNotifications($user->getId());
        $topics[] = Topics::userEvents($user->getId());
        $topics[] = Topics::userConversationActivity($user->getId());
        $topics[] = Topics::presenceTemplate();

        foreach ($viewableChannels as $channel) {
            $topics[] = Topics::channelThreads($channel->getCommunity()->getIdentifier(), $channel->getIdentifier());
        }

        foreach ($this->conversationService->listForCurrentUser() as $conversation) {
            $topics[] = $conversation->getConversationIri();
            $topics[] = Topics::conversationThreads($conversation->getConversationIri());
        }

        $dto = new RealtimeTokenDto();
        $dto->token = $this->tokenFactory->create($topics);
        $dto->expiresAt = time() + $this->tokenTtl;

        return $dto;
    }
}
