<?php

declare(strict_types=1);

namespace App\State\Message\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Message;
use App\Security\ApiKeyScopeGuard;
use App\Service\Message\MessageServiceInterface;

/** @implements ProviderInterface<Message> */
final readonly class MessageByIdProvider implements ProviderInterface
{
    public function __construct(
        private MessageServiceInterface $messageService,
        private ApiKeyScopeGuard $scopeGuard,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Message
    {
        $message = $this->messageService->getById((string) $uriVariables['id']);
        if (null !== $message->getConversation()) {
            $this->scopeGuard->requireScope('conversations:read');
        }
        $this->messageService->hydrateText($message);

        return $message;
    }
}
