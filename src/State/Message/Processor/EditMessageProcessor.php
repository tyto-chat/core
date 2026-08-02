<?php

declare(strict_types=1);

namespace App\State\Message\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Message\UpdateMessageDto;
use App\Entity\Message;
use App\Security\ApiKeyScopeGuard;
use App\Service\Message\MessageServiceInterface;

/**
 * @implements ProcessorInterface<UpdateMessageDto, Message>
 */
final readonly class EditMessageProcessor implements ProcessorInterface
{
    public function __construct(
        private MessageServiceInterface $messageService,
        private ApiKeyScopeGuard $scopeGuard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Message
    {
        /** @var UpdateMessageDto $data */
        /** @var Message $message */
        $message = $context['read_data'];
        if (null !== $message->getConversation()) {
            $this->scopeGuard->requireScope('conversations:write');
        }

        return $this->messageService->update($message, $data);
    }
}
