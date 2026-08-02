<?php

declare(strict_types=1);

namespace App\State\Notification\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Service\Notification\NotificationServiceInterface;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class MarkAllDmNotificationsReadProcessor implements ProcessorInterface
{
    public function __construct(
        private NotificationServiceInterface $notificationService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->notificationService->markAllDmAsRead();

        return null;
    }
}
