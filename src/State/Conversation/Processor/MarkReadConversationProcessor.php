<?php

declare(strict_types=1);

namespace App\State\Conversation\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Conversation;
use App\Service\Conversation\ConversationServiceInterface;

/**
 * @implements ProcessorInterface<Conversation, Conversation>
 */
final readonly class MarkReadConversationProcessor implements ProcessorInterface
{
    public function __construct(
        private ConversationServiceInterface $conversationService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Conversation
    {
        \assert($data instanceof Conversation);
        $this->conversationService->markRead($data);

        return $data;
    }
}
