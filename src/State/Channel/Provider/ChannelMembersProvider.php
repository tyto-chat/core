<?php

declare(strict_types=1);

namespace App\State\Channel\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Service\Channel\ChannelMembershipServiceInterface;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityServiceInterface;

/**
 * @implements ProviderInterface<\App\Entity\ChannelMember>
 */
final readonly class ChannelMembersProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private ChannelServiceInterface $channelService,
        private ChannelMembershipServiceInterface $channelMembershipService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        $community = $this->communityService->getByIdentifier($uriVariables['community']);
        $channel = $this->channelService->getByIdentifier($uriVariables['channel'], $community);

        return $this->channelMembershipService->getMembers($channel);
    }
}
