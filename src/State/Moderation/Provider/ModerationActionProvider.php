<?php

declare(strict_types=1);

namespace App\State\Moderation\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\ModerationAction;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Moderation\ModerationServiceInterface;

/**
 * @implements ProviderInterface<ModerationAction>
 */
final readonly class ModerationActionProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private ModerationServiceInterface $moderationService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ModerationAction
    {
        $community = $this->communityService->getByIdentifier($uriVariables['community']);

        return $this->moderationService->getAction((int) $uriVariables['id'], $community);
    }
}
