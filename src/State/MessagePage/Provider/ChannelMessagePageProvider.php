<?php

declare(strict_types=1);

namespace App\State\MessagePage\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\MessagePage;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityServiceInterface;

/** @implements ProviderInterface<MessagePage> */
final readonly class ChannelMessagePageProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private ChannelServiceInterface $channelService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MessagePage
    {
        $community = $this->communityService->getByIdentifier($uriVariables['community']);
        $channel = $this->channelService->getByIdentifier($uriVariables['channel'], $community);

        return $this->channelService->getChannelPage($channel, (int) $uriVariables['pageNumber']);
    }
}
