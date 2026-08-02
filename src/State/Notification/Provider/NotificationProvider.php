<?php

declare(strict_types=1);

namespace App\State\Notification\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Notification\NotificationServiceInterface;

/**
 * @implements ProviderInterface<\App\Entity\Notification>
 */
final readonly class NotificationProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private NotificationServiceInterface $notificationService,
    ) {
    }

    /** @return list<\App\Entity\Notification> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $community = $this->communityService->getByIdentifier($uriVariables['identifier']);

        return $this->notificationService->getAllForCommunity($community);
    }
}
