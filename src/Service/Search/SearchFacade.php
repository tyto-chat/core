<?php

declare(strict_types=1);

namespace App\Service\Search;

use ApiPlatform\Metadata\IriConverterInterface;
use App\Dto\Search\SearchHitDto;
use App\Dto\Search\SearchOptions;
use App\Dto\Search\SearchResultDto;
use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Security\SecurityContext;
use App\Security\Voter\ChannelVoter;
use App\Security\Voter\CommunityVoter;
use App\Security\Voter\ConversationVoter;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Message\MessageServiceInterface;

final class SearchFacade implements SearchFacadeInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly SearchServiceInterface $searchService,
        private readonly MessageServiceInterface $messageService,
        private readonly MessageRepository $messageRepository,
        private readonly ChannelServiceInterface $channelService,
        private readonly IriConverterInterface $iriConverter,
    ) {
    }

    public function searchChannel(Channel $channel, string $query, ?SearchOptions $options = null): SearchResultDto
    {
        $this->security->throwAccessDeniedUnlessGranted(ChannelVoter::VIEW, $channel, 'You do not have access to this channel.');

        $result = $this->searchService->searchChannel($channel, $query, $options);

        return $this->shape($result, $channel, null);
    }

    public function searchConversation(Conversation $conversation, string $query, ?SearchOptions $options = null): SearchResultDto
    {
        $this->security->throwAccessDeniedUnlessGranted(ConversationVoter::VIEW, $conversation, 'You do not have access to this conversation.');

        $result = $this->searchService->searchConversation($conversation, $query, $options);

        return $this->shape($result, null, $conversation);
    }

    public function searchCommunity(Community $community, string $query, ?SearchOptions $options = null): SearchResultDto
    {
        $this->security->throwAccessDeniedUnlessGranted(CommunityVoter::VIEW, $community, 'You do not have access to this community.');

        $channels = $this->channelService->getViewableTextChannels($community);
        if ([] === $channels) {
            return new SearchResultDto();
        }

        $visibleById = [];
        foreach ($channels as $channel) {
            $visibleById[(int) $channel->getId()] = $channel;
        }

        $result = $this->searchService->searchChannels(array_keys($visibleById), $query, $options);

        return $this->shape($result, null, null, $community, $visibleById);
    }

    /**
     * @param array{hits: array<int, array<string, mixed>>, total: int, limit: int, offset: int} $result
     * @param array<int, Channel>                                                                $visibleById
     */
    private function shape(array $result, ?Channel $channel, ?Conversation $conversation, ?Community $community = null, array $visibleById = []): SearchResultDto
    {
        $ids = [];
        foreach ($result['hits'] as $hit) {
            $id = (string) ($hit['id'] ?? '');
            if ('' !== $id) {
                $ids[] = $id;
            }
        }

        /** @var array<int, Message> $found */
        $found = [] === $ids ? [] : $this->messageRepository->findBy(['id' => $ids]);
        $messagesById = [];
        foreach ($found as $message) {
            $this->messageService->hydrateText($message);
            $messagesById[$message->getId()] = $message;
        }

        $hits = [];

        foreach ($result['hits'] as $hit) {
            $id = (string) ($hit['id'] ?? '');
            if ('' === $id) {
                continue;
            }

            $entity = $messagesById[$id] ?? null;
            if (null === $entity) {
                continue;
            }

            $hitChannel = $channel ?? $entity->getChannel();
            if (null !== $community) {
                if (null === $hitChannel
                    || !isset($visibleById[(int) $hitChannel->getId()])
                    || $hitChannel->getCommunity()?->getId() !== $community->getId()) {
                    continue;
                }
            }

            $formatted = $hit['_formatted'] ?? [];
            $snippet = \is_array($formatted) && isset($formatted['text']) ? (string) $formatted['text'] : (string) ($hit['text'] ?? '');

            $hits[] = new SearchHitDto(
                messageIri: $this->iriConverter->getIriFromResource($entity),
                messageId: $id,
                snippet: $snippet,
                text: (string) ($hit['text'] ?? ''),
                authorId: isset($hit['authorId']) ? (int) $hit['authorId'] : null,
                authorName: isset($hit['authorName']) ? (string) $hit['authorName'] : null,
                createdAt: isset($hit['createdAt']) ? (int) $hit['createdAt'] : null,
                pageNumber: isset($hit['pageNumber']) ? (int) $hit['pageNumber'] : null,
                communityIdentifier: $hitChannel?->getCommunity()?->getIdentifier(),
                channelIdentifier: $hitChannel?->getIdentifier(),
                conversationIdentifier: $conversation?->getIdentifier(),
                message: $entity,
            );
        }

        return new SearchResultDto(
            hits: $hits,
            total: $result['total'],
            limit: $result['limit'],
            offset: $result['offset'],
        );
    }
}
