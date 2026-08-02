<?php

declare(strict_types=1);

namespace App\State\Conversation\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Conversation\MuteConversationDto;
use App\Entity\Conversation;
use App\Service\Conversation\ConversationServiceInterface;

/**
 * @implements ProcessorInterface<MuteConversationDto, Conversation>
 */
final readonly class MuteConversationProcessor implements ProcessorInterface
{
    public function __construct(
        private ConversationServiceInterface $conversationService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Conversation
    {
        /** @var MuteConversationDto $data */
        $conversation = $this->conversationService->getByIdentifier((string) $uriVariables['conversation']);
        $this->conversationService->setMuted($conversation, $data->mutedUntil);

        return $conversation;
    }
}
