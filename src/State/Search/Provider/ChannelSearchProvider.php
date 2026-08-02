<?php

declare(strict_types=1);

namespace App\State\Search\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Search\SearchResultDto;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Search\SearchFacadeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<SearchResultDto>
 */
final readonly class ChannelSearchProvider implements ProviderInterface
{
    public function __construct(
        private SearchFacadeInterface $searchFacade,
        private CommunityServiceInterface $communityService,
        private ChannelServiceInterface $channelService,
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

        $community = $this->communityService->getByIdentifier((string) $uriVariables['communityIdentifier']);
        $channel = $this->channelService->getByIdentifier((string) $uriVariables['channelIdentifier'], $community);

        return $this->searchFacade->searchChannel($channel, $q, $this->queryParser->options($request));
    }
}
