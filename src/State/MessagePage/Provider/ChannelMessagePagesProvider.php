<?php

declare(strict_types=1);

namespace App\State\MessagePage\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\ArrayPaginator;
use ApiPlatform\State\ProviderInterface;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityServiceInterface;

/** @implements ProviderInterface<\App\Entity\MessagePage> */
final readonly class ChannelMessagePagesProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private ChannelServiceInterface $channelService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ArrayPaginator
    {
        $community = $this->communityService->getByIdentifier($uriVariables['community']);
        $channel = $this->channelService->getByIdentifier($uriVariables['channel'], $community);
        $pages = $this->channelService->getChannelPages($channel);

        return new ArrayPaginator($pages, 0, count($pages));
    }
}
