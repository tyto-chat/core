<?php

declare(strict_types=1);

namespace App\State\Search\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Search\SearchResultDto;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Search\SearchFacadeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<SearchResultDto>
 */
final readonly class CommunitySearchProvider implements ProviderInterface
{
    public function __construct(
        private SearchFacadeInterface $searchFacade,
        private CommunityServiceInterface $communityService,
        private RequestStack $requestStack,
        private SearchQueryParser $queryParser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SearchResultDto
    {
        $request = $this->requestStack->getCurrentRequest();
        $q = (string) ($request?->query->get('q', '') ?? '');

        if ($this->queryParser->isTooShort($q)) {
            return new SearchResultDto();
        }

        $community = $this->communityService->getByIdentifier((string) $uriVariables['identifier']);

        return $this->searchFacade->searchCommunity($community, $q, $this->queryParser->options($request));
    }
}
