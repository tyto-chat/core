<?php

declare(strict_types=1);

namespace App\State\Conversation\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Service\Conversation\ConversationServiceInterface;

/**
 * @implements ProviderInterface<\App\Entity\Conversation>
 */
final readonly class ConversationsProvider implements ProviderInterface
{
    public function __construct(
        private ConversationServiceInterface $conversationService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        return $this->conversationService->listForCurrentUser();
    }
}
