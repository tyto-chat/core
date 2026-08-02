<?php

declare(strict_types=1);

namespace App\State\Moderation\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\ModerationAction;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Moderation\ModerationServiceInterface;
use App\Service\User\UserServiceInterface;

/**
 * @implements ProviderInterface<ModerationAction>
 */
final readonly class ModerationLogProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private ModerationServiceInterface $moderationService,
        private UserServiceInterface $userService,
    ) {
    }

    /** @return list<ModerationAction> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $request = $context['request'] ?? null;
        $community = $this->communityService->getByIdentifier($uriVariables['community']);
        $page = (int) ($request?->query->get('page') ?? 1);
        $typeFilter = $request?->query->get('type') ?: null;
        $activeOnly = $request?->query->getBoolean('active', false) ?? false;

        $targetUser = null;
        $targetUserId = $request?->query->get('targetUserId');
        if (null !== $targetUserId) {
            try {
                $targetUser = $this->userService->get((int) $targetUserId);
            } catch (\App\Exception\User\UserNotFoundException) {
                // Unknown target filter must never silently widen to the full log
                return [];
            }
        }

        return $this->moderationService->getLog($community, $page, null, $typeFilter, $activeOnly, $targetUser);
    }
}
