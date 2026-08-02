<?php

declare(strict_types=1);

namespace App\State\Message\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Message\SendMessageDto;
use App\Entity\Message;
use App\Security\ApiKeyScopeGuard;
use App\Service\Message\MessageServiceInterface;

/**
 * @implements ProcessorInterface<SendMessageDto, Message>
 */
final readonly class ReplyToMessageProcessor implements ProcessorInterface
{
    public function __construct(
        private MessageServiceInterface $messageService,
        private ApiKeyScopeGuard $scopeGuard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Message
    {
        /** @var SendMessageDto $data */
        $root = $this->messageService->getById((string) $uriVariables['id']);
        if (null !== $root->getConversation()) {
            $this->scopeGuard->requireScope('conversations:write');
        }

        return $this->messageService->reply($root, $data->text, array_values($data->attachmentIris));
    }
}
