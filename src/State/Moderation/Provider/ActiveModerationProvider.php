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
final readonly class ActiveModerationProvider implements ProviderInterface
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
        $community = $this->communityService->getByIdentifier($uriVariables['community']);
        $userId = $uriVariables['userId'];
        $target = $this->userService->get((int) $userId);

        return $this->moderationService->getActiveActions($community, $target);
    }
}
