<?php

declare(strict_types=1);

namespace App\State\Community\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Service\Channel\ChannelReadStateServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Notification\NotificationServiceInterface;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class MarkCommunityReadProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private ChannelReadStateServiceInterface $channelReadStateService,
        private NotificationServiceInterface $notificationService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $community = $this->communityService->getByIdentifier((string) $uriVariables['identifier']);
        $this->channelReadStateService->markAllReadInCommunity($community);
        $this->notificationService->markAllAsReadForCommunity($community);

        return null;
    }
}
