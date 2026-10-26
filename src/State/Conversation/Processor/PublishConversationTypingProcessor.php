<?php

declare(strict_types=1);

namespace App\State\Conversation\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Conversation;
use App\Service\Conversation\ConversationServiceInterface;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class PublishConversationTypingProcessor implements ProcessorInterface
{
    public function __construct(
        private ConversationServiceInterface $conversationService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        /** @var Conversation $conversation */
        $conversation = $context['read_data'];

        $this->conversationService->publishTyping($conversation);

        return null;
    }
}
