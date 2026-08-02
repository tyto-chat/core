<?php

declare(strict_types=1);

namespace App\State\Notification\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\UnreadCounts;
use App\Service\Notification\NotificationServiceInterface;

/**
 * @implements ProviderInterface<UnreadCounts>
 */
final readonly class UnreadCountsProvider implements ProviderInterface
{
    public function __construct(
        private NotificationServiceInterface $notificationService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UnreadCounts
    {
        $resource = new UnreadCounts();
        $resource->counts = $this->notificationService->getUnreadCounts();

        return $resource;
    }
}
