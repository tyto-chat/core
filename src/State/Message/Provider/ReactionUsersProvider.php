<?php

declare(strict_types=1);

namespace App\State\Message\Provider;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ReactionUserDto;
use App\Exception\Reaction\ReactionNotFoundException;
use App\Security\ApiKeyScopeGuard;
use App\Service\Message\MessageServiceInterface;
use App\Service\User\UserServiceInterface;

/**
 * @implements ProviderInterface<ReactionUserDto>
 */
final readonly class ReactionUsersProvider implements ProviderInterface
{
    public function __construct(
        private MessageServiceInterface $messageService,
        private UserServiceInterface $userService,
        private IriConverterInterface $iriConverter,
        private ApiKeyScopeGuard $scopeGuard,
    ) {
    }

    /** @return ReactionUserDto[] */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $message = $this->messageService->getById((string) $uriVariables['id']);
        if (null !== $message->getConversation()) {
            $this->scopeGuard->requireScope('conversations:read');
        }
        $emoji = (string) $uriVariables['emoji'];

        $reactions = $message->getReactions();
        if (!isset($reactions[$emoji]) || [] === $reactions[$emoji]) {
            throw new ReactionNotFoundException(sprintf('No reactions found for emoji "%s".', $emoji));
        }

        $userIds = array_column($reactions[$emoji], 'userId');
        $users = $this->userService->findByIds($userIds);

        $orderedIndex = array_flip($userIds);
        usort($users, static fn ($a, $b) => ($orderedIndex[$a->getId()] ?? 0) <=> ($orderedIndex[$b->getId()] ?? 0));

        return array_map(fn ($user): ReactionUserDto => new ReactionUserDto(
            profile: $this->iriConverter->getIriFromResource($user->getProfile()),
            name: (string) $user->getProfile()?->getName(),
        ), $users);
    }
}
