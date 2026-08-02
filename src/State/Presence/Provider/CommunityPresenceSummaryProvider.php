<?php

declare(strict_types=1);

namespace App\State\Presence\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Presence\CommunityPresenceSummaryDto;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Presence\GuestPresenceServiceInterface;
use App\Service\Presence\PresenceServiceInterface;

/**
 * @implements ProviderInterface<CommunityPresenceSummaryDto>
 */
final readonly class CommunityPresenceSummaryProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private PresenceServiceInterface $presenceService,
        private GuestPresenceServiceInterface $guestPresence,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CommunityPresenceSummaryDto
    {
        $community = $this->communityService->getByIdentifier((string) $uriVariables['identifier']);

        return new CommunityPresenceSummaryDto(
            $this->presenceService->getCommunityOnlineCount($community),
            $community->isPrivate() ? 0 : $this->guestPresence->getGuestCount($community),
        );
    }
}
