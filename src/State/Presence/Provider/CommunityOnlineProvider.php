<?php

declare(strict_types=1);

namespace App\State\Presence\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Presence\CommunityOnlineDto;
use App\Dto\Presence\PresenceEntryDto;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Presence\PresenceServiceInterface;

/**
 * @implements ProviderInterface<CommunityOnlineDto>
 */
final readonly class CommunityOnlineProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private PresenceServiceInterface $presenceService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CommunityOnlineDto
    {
        $community = $this->communityService->getByIdentifier((string) $uriVariables['identifier']);

        $users = [];
        foreach ($this->presenceService->getCommunityOnline($community) as $snapshot) {
            $users[] = new PresenceEntryDto($snapshot->userId, $snapshot->state);
        }

        return new CommunityOnlineDto($users);
    }
}
