<?php

declare(strict_types=1);

namespace App\State\Conversation\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Conversation;
use App\Service\Conversation\ConversationServiceInterface;

/**
 * @implements ProviderInterface<Conversation>
 */
final readonly class ConversationProvider implements ProviderInterface
{
    public function __construct(
        private ConversationServiceInterface $conversationService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Conversation
    {
        return $this->conversationService->getByIdentifier((string) $uriVariables['conversation']);
    }
}
