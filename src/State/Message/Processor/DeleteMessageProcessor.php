<?php

declare(strict_types=1);

namespace App\State\Message\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Message;
use App\Security\ApiKeyScopeGuard;
use App\Service\Message\MessageServiceInterface;

/**
 * @implements ProcessorInterface<Message, null>
 */
final readonly class DeleteMessageProcessor implements ProcessorInterface
{
    public function __construct(
        private MessageServiceInterface $messageService,
        private ApiKeyScopeGuard $scopeGuard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        /** @var Message $data */
        if (null !== $data->getConversation()) {
            $this->scopeGuard->requireScope('conversations:write');
        }
        $this->messageService->delete($data);

        return null;
    }
}
