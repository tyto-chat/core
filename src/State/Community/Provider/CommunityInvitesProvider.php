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
final readonly class CommunityInvitesProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private CommunityInviteServiceInterface $inviteService,
    ) {
    }

    /** @return list<CommunityInvite> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $community = $this->communityService->getByIdentifier($uriVariables['community']);

        return array_values($this->inviteService->listForCommunity($community));
    }
}
