<?php

declare(strict_types=1);

namespace App\State\E2ee\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\E2ee\ItemsDto;
use App\Dto\E2ee\LeaveRequestDto;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\E2ee\GroupDeliveryServiceInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProviderInterface<ItemsDto>
 */
final readonly class LeaveRequestsProvider implements ProviderInterface
{
    public function __construct(
        private ConversationServiceInterface $conversations,
        private GroupDeliveryServiceInterface $delivery,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ItemsDto
    {
        $request = $context['request'] ?? null;
        $identifier = $request instanceof Request ? $request->query->getString('conversation') : '';
        $conversation = $this->conversations->getByIdentifier($identifier);

        return new ItemsDto(array_map(LeaveRequestDto::fromRequest(...), $this->delivery->listLeaveRequests($conversation)));
    }
}
