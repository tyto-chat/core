<?php

declare(strict_types=1);

namespace App\Async\Handler;

use App\Async\DispatchMessageNotificationsMessage;
use App\Repository\MessageRepository;
use App\Service\Message\MessageNotificationDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DispatchMessageNotificationsHandler
{
    public function __construct(
        private MessageRepository $messageRepository,
        private MessageNotificationDispatcherInterface $notificationDispatcher,
    ) {
    }

    public function __invoke(DispatchMessageNotificationsMessage $message): void
    {
        $entity = $this->messageRepository->find($message->messageId);
        if (null === $entity) {
            return;
        }

        $this->notificationDispatcher->dispatchFor($entity);
    }
}
