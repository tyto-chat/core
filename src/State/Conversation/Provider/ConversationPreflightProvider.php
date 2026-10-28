<?php

declare(strict_types=1);

namespace App\State\Conversation\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ConversationPreflight;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\User\UserServiceInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<ConversationPreflight>
 */
final readonly class ConversationPreflightProvider implements ProviderInterface
{
    private const int MAX_MEMBERS = 50;

    public function __construct(
        private ConversationServiceInterface $conversations,
        private UserServiceInterface $users,
        private RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ConversationPreflight
    {
        $raw = $this->requestStack->getCurrentRequest()?->query->all()['memberUserIds'] ?? [];
        $ids = array_slice(array_values(array_unique(array_map(intval(...), array_filter(\is_array($raw) ? $raw : [], is_numeric(...))))), 0, self::MAX_MEMBERS);

        $result = $this->conversations->preflight($this->users->findByIds($ids));
        $decision = $result->decision;

        return new ConversationPreflight(
            $decision->outcome->value,
            $decision->reason?->value,
            $decision->refusingUserIds,
            $decision->unreachableUserIds,
            $result->existingIdentifier,
        );
    }
}
