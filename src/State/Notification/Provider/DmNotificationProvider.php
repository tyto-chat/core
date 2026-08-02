<?php

declare(strict_types=1);

namespace App\State\Notification\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Service\Notification\NotificationServiceInterface;

/**
 * @implements ProviderInterface<\App\Entity\Notification>
 */
final readonly class DmNotificationProvider implements ProviderInterface
{
    public function __construct(
        private NotificationServiceInterface $notificationService,
    ) {
    }

    /** @return list<\App\Entity\Notification> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_values($this->notificationService->getAllDmForCurrentUser());
    }
}
