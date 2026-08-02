<?php

declare(strict_types=1);

namespace App\State\Presence\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Presence\PresenceHistoryDto;
use App\Dto\Presence\PresenceSamplePointDto;
use App\Exception\Community\CommunityNotFoundException;
use App\Repository\PresenceSampleRepository;
use App\Security\SecurityContext;
use App\Service\Community\CommunityServiceInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<PresenceHistoryDto>
 */
final readonly class PresenceHistoryProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private PresenceSampleRepository $samples,
        private SecurityContext $security,
        private RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PresenceHistoryDto
    {
        $this->security->throwAccessDeniedUnlessAuthenticated('You must be signed in to view presence history.');
        $community = $this->communityService->getByIdentifier((string) $uriVariables['identifier']);

        if (!$this->security->isAdmin() && !$this->security->isCommunityAdmin($community)) {
            throw new CommunityNotFoundException(sprintf('Community not found using criteria: {"identifier":"%s"}.', $community->getIdentifier()));
        }

        $days = (int) ($this->requestStack->getCurrentRequest()?->query->get('days', '7') ?? '7');
        $days = max(1, min(90, $days));

        $points = [];
        foreach ($this->samples->findForCommunitySince($community, new \DateTimeImmutable(sprintf('-%d days', $days))) as $sample) {
            $points[] = new PresenceSamplePointDto(
                $sample->getSampledAt()->format(\DateTimeInterface::ATOM),
                $sample->getMembersOnline(),
                $sample->getGuestsOnline(),
            );
        }

        return new PresenceHistoryDto($points);
    }
}
