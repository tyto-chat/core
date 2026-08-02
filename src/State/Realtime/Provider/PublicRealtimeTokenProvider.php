<?php

declare(strict_types=1);

namespace App\State\Realtime\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Realtime\RealtimeTokenDto;
use App\Entity\Channel;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Realtime\MercureSubscriberTokenFactory;
use App\Utils\Topics;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @implements ProviderInterface<RealtimeTokenDto>
 */
final readonly class PublicRealtimeTokenProvider implements ProviderInterface
{
    public function __construct(
        private MercureSubscriberTokenFactory $tokenFactory,
        private ChannelServiceInterface $channelService,
        #[Autowire(param: 'lexik_jwt_authentication.token_ttl')]
        private int $tokenTtl,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): RealtimeTokenDto
    {
        $dto = new RealtimeTokenDto();

        $channels = $this->channelService->getPublicTextChannels();
        if ([] === $channels) {
            return $dto;
        }

        $topics = array_map(
            static fn (Channel $c): string => Topics::channel($c->getCommunity()->getIdentifier(), $c->getIdentifier()),
            $channels
        );

        $communityIdentifiers = [];
        foreach ($channels as $channel) {
            $communityIdentifiers[$channel->getCommunity()->getIdentifier()] = true;
        }
        foreach (array_keys($communityIdentifiers) as $identifier) {
            $topics[] = Topics::community((string) $identifier);
            $topics[] = Topics::communityEmojis((string) $identifier);
        }
        foreach ($channels as $channel) {
            $topics[] = Topics::channelThreads($channel->getCommunity()->getIdentifier(), $channel->getIdentifier());
        }

        $dto->token = $this->tokenFactory->create($topics);
        $dto->expiresAt = time() + $this->tokenTtl;

        return $dto;
    }
}
