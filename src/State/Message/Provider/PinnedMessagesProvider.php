<?php

declare(strict_types=1);

namespace App\State\Message\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Message;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Message\MessageServiceInterface;

/** @implements ProviderInterface<Message> */
final readonly class PinnedMessagesProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private ChannelServiceInterface $channelService,
        private MessageServiceInterface $messageService,
    ) {
    }

    /**
     * @return Message[]
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $community = $this->communityService->getByIdentifier($uriVariables['community']);
        $channel = $this->channelService->getByIdentifier($uriVariables['channel'], $community);

        return $this->messageService->findPinnedForChannel($channel);
    }
}
