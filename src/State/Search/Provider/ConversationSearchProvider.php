<?php

declare(strict_types=1);

namespace App\State\Search\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Search\SearchResultDto;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\Search\SearchFacadeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<SearchResultDto>
 */
final readonly class ConversationSearchProvider implements ProviderInterface
{
    public function __construct(
        private SearchFacadeInterface $searchFacade,
        private ConversationServiceInterface $conversationService,
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

        $conversation = $this->conversationService->getByIdentifier((string) $uriVariables['identifier']);

        return $this->searchFacade->searchConversation($conversation, $q, $this->queryParser->options($request));
    }
}
