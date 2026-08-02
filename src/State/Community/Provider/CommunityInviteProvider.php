<?php

declare(strict_types=1);

namespace App\State\Community\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\CommunityInvite;
use App\Service\Community\CommunityInviteServiceInterface;
use App\Service\Community\CommunityServiceInterface;

/**
 * @implements ProviderInterface<CommunityInvite>
 */
final readonly class CommunityInviteProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private CommunityInviteServiceInterface $inviteService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CommunityInvite
    {
        $community = $this->communityService->getByIdentifier($uriVariables['community']);

        return $this->inviteService->getById((int) $uriVariables['id'], $community);
    }
}
