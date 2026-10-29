<?php

declare(strict_types=1);

namespace App\State\Message\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Message\SendMessageDto;
use App\Entity\Message;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\Message\MessageServiceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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

        $message = $this->messageService->sendToConversation($conversation, $data->text, array_values($data->attachmentIris), $data->id);
        $request = $context['request'] ?? null;
        if ($message->isResent() && $request instanceof Request) {
            $request->attributes->set('_api_response_status', Response::HTTP_OK);
        }

        return $message;
    }
}
