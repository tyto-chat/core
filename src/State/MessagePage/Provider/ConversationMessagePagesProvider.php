<?php

declare(strict_types=1);

namespace App\State\MessagePage\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\ArrayPaginator;
use ApiPlatform\State\ProviderInterface;
use App\Service\Conversation\ConversationServiceInterface;

/** @implements ProviderInterface<\App\Entity\MessagePage> */
final readonly class ConversationMessagePagesProvider implements ProviderInterface
{
    public function __construct(
        private ConversationServiceInterface $conversationService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ArrayPaginator
    {
        $conversation = $this->conversationService->getByIdentifier((string) $uriVariables['conversation']);
        $pages = $this->conversationService->getPages($conversation);

        return new ArrayPaginator($pages, 0, count($pages));
    }
}
