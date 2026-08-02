<?php

declare(strict_types=1);

namespace App\State\Community\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Channel\UnreadChannelsDto;
use App\Service\Channel\ChannelReadStateServiceInterface;
use App\Service\Community\CommunityServiceInterface;

/**
 * @implements ProviderInterface<UnreadChannelsDto>
 */
final readonly class ChannelUnreadProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private ChannelReadStateServiceInterface $channelReadStateService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UnreadChannelsDto
    {
        $community = $this->communityService->getByIdentifier((string) $uriVariables['identifier']);

        return new UnreadChannelsDto($this->channelReadStateService->getUnreadChannelIdentifiers($community));
    }
}
