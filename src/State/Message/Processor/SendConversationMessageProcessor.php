<?php

declare(strict_types=1);

namespace App\State\Message\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Message\SendMessageDto;
use App\Entity\Message;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\Message\MessageServiceInterface;

/**
 * @implements ProcessorInterface<SendMessageDto, Message>
 */
final readonly class SendConversationMessageProcessor implements ProcessorInterface
{
    public function __construct(
        private ConversationServiceInterface $conversationService,
        private MessageServiceInterface $messageService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Message
    {
        /** @var SendMessageDto $data */
        $conversation = $this->conversationService->getByIdentifier((string) $uriVariables['conversation']);

        return $this->messageService->sendToConversation($conversation, $data->text, array_values($data->attachmentIris));
    }
}
