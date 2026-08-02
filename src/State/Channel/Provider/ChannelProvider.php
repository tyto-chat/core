<?php

declare(strict_types=1);

namespace App\State\Channel\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Channel;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityServiceInterface;

/**
 * @implements ProviderInterface<Channel>
 */
final readonly class ChannelProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private ChannelServiceInterface $channelService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Channel
    {
        $community = $this->communityService->getByIdentifier($uriVariables['community']);

        return $this->channelService->getByIdentifier($uriVariables['channel'], $community);
    }
}
