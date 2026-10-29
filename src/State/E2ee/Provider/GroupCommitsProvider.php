<?php

declare(strict_types=1);

namespace App\State\E2ee\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\E2ee\CommitDto;
use App\Dto\E2ee\ItemsDto;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\E2ee\GroupDeliveryServiceInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProviderInterface<ItemsDto>
 */
final readonly class GroupCommitsProvider implements ProviderInterface
{
    public function __construct(
        private ConversationServiceInterface $conversations,
        private GroupDeliveryServiceInterface $delivery,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ItemsDto
    {
        $request = $context['request'] ?? null;
        $after = $request instanceof Request ? $request->query->getInt('after') : 0;
        $conversation = $this->conversations->getByIdentifier((string) $uriVariables['conversation']);

        return new ItemsDto(array_map(CommitDto::fromEpoch(...), $this->delivery->listCommits($conversation, $after)));
    }
}
