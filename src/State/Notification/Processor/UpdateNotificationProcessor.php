<?php

declare(strict_types=1);

namespace App\State\Notification\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Notification\UpdateNotificationDto;
use App\Entity\Notification;
use App\Service\Notification\NotificationServiceInterface;

/**
 * @implements ProcessorInterface<UpdateNotificationDto, Notification>
 */
final readonly class UpdateNotificationProcessor implements ProcessorInterface
{
    public function __construct(
        private NotificationServiceInterface $notificationService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Notification
    {
        /** @var UpdateNotificationDto $data */
        /** @var Notification $notification */
        $notification = $context['read_data'];

        if ($data->isRead) {
            return $this->notificationService->markAsRead($notification);
        }

        return $notification;
    }
}
