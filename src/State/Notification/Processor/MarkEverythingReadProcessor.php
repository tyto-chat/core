<?php

declare(strict_types=1);

namespace App\State\Notification\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Service\Channel\ChannelReadStateServiceInterface;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\Notification\NotificationServiceInterface;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class MarkEverythingReadProcessor implements ProcessorInterface
{
    public function __construct(
        private ChannelReadStateServiceInterface $channelReadStateService,
        private ConversationServiceInterface $conversationService,
        private NotificationServiceInterface $notificationService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->channelReadStateService->markAllReadEverywhere();
        $this->conversationService->markAllRead();
        $this->notificationService->markAllAsRead();

        return null;
    }
}
