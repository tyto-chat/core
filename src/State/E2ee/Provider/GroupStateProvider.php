<?php

declare(strict_types=1);

namespace App\State\E2ee\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\E2ee\GroupStateDto;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\E2ee\GroupDeliveryServiceInterface;

/**
 * @implements ProviderInterface<GroupStateDto>
 */
final readonly class GroupStateProvider implements ProviderInterface
{
    public function __construct(
        private ConversationServiceInterface $conversations,
        private GroupDeliveryServiceInterface $delivery,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): GroupStateDto
    {
        $state = $this->delivery->state($this->conversations->getByIdentifier((string) $uriVariables['conversation']));

        return new GroupStateDto($state['epoch'], $state['generation'], $state['groupInfo'], $state['frozenGenerations']);
    }
}
