<?php

declare(strict_types=1);

namespace App\State\MessagePage\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\MessagePage;
use App\Service\Conversation\ConversationServiceInterface;

/** @implements ProviderInterface<MessagePage> */
final readonly class ConversationMessagePageProvider implements ProviderInterface
{
    public function __construct(
        private ConversationServiceInterface $conversationService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MessagePage
    {
        $conversation = $this->conversationService->getByIdentifier((string) $uriVariables['conversation']);

        return $this->conversationService->getPage($conversation, (int) $uriVariables['pageNumber']);
    }
}
