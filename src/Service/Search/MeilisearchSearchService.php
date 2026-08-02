<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Dto\Search\SearchOptions;
use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Exception\Search\SearchUnavailableException;
use App\Utils\MessageDocumentBuilder;
use Meilisearch\Client;
use Psr\Log\LoggerInterface;

final class MeilisearchSearchService implements SearchServiceInterface
{
    public const INDEX_MESSAGES = 'messages';

    private bool $indexConfigured = false;

    public function __construct(
        private readonly Client $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function ensureIndexConfigured(): void
    {
        if ($this->indexConfigured) {
            return;
        }

        $index = $this->client->index(self::INDEX_MESSAGES);

        try {
            $this->client->getIndex(self::INDEX_MESSAGES);
        } catch (\Throwable) {
            $this->client->createIndex(self::INDEX_MESSAGES, ['primaryKey' => 'id']);
        }

        $index->updateSearchableAttributes(['text', 'authorName']);
        $index->updateFilterableAttributes(['channelId', 'conversationId', 'authorId', 'createdAt']);
        $index->updateSortableAttributes(['createdAt']);

        $this->indexConfigured = true;
    }

    public function indexMessages(array $messages): void
    {
        $documents = [];
        foreach ($messages as $message) {
            if (!MessageDocumentBuilder::isIndexable($message)) {
                $this->removeMessage($message->getId());
                continue;
            }
            $documents[] = MessageDocumentBuilder::build($message);
        }
        if ([] === $documents) {
            return;
        }

        try {
            $this->ensureIndexConfigured();
            $this->client->index(self::INDEX_MESSAGES)->addDocuments($documents, 'id');
        } catch (\Throwable $e) {
            $this->logger->error('meilisearch indexMessages failed', [
                'count' => count($documents),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function indexMessage(Message $message): void
    {
        if (!MessageDocumentBuilder::isIndexable($message)) {
            $this->removeMessage($message->getId());

            return;
        }

        try {
            $this->ensureIndexConfigured();
            $this->client->index(self::INDEX_MESSAGES)->addDocuments([
                MessageDocumentBuilder::build($message),
            ], 'id');
        } catch (\Throwable $e) {
            $this->logger->error('meilisearch indexMessage failed', [
                'messageId' => $message->getId(),
                'error' => $e->getMessage(),
            ]);

            // Rethrow so messenger NACKs and retries — swallowing acks and silently drops the doc.
            throw $e;
        }
    }

    public function removeMessage(string $messageId): void
    {
        try {
            $this->client->index(self::INDEX_MESSAGES)->deleteDocument($messageId);
        } catch (\Throwable $e) {
            $this->logger->error('meilisearch removeMessage failed', [
                'messageId' => $messageId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function removeChannelDocuments(int $channelId): void
    {
        try {
            $this->client->index(self::INDEX_MESSAGES)->deleteDocuments(['filter' => 'channelId = '.$channelId]);
        } catch (\Throwable $e) {
            $this->logger->error('meilisearch removeChannelDocuments failed', [
                'channelId' => $channelId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function searchChannel(Channel $channel, string $query, ?SearchOptions $options = null): array
    {
        return $this->runSearch($query, ['channelId = '.(int) $channel->getId()], $options ?? new SearchOptions());
    }

    public function searchConversation(Conversation $conversation, string $query, ?SearchOptions $options = null): array
    {
        return $this->runSearch($query, ['conversationId = '.(int) $conversation->getId()], $options ?? new SearchOptions());
    }

    public function searchChannels(array $channelIds, string $query, ?SearchOptions $options = null): array
    {
        if ([] === $channelIds) {
            return ['hits' => [], 'total' => 0, 'limit' => ($options ?? new SearchOptions())->limit, 'offset' => ($options ?? new SearchOptions())->offset];
        }

        $ids = implode(', ', array_map(intval(...), $channelIds));

        return $this->runSearch($query, ['channelId IN ['.$ids.']'], $options ?? new SearchOptions());
    }

    /**
     * @param array<int, string> $baseFilters
     *
     * @return array{hits: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    private function runSearch(string $query, array $baseFilters, SearchOptions $opts): array
    {
        $limit = max(1, min($opts->limit, 100));
        $offset = max(0, $opts->offset);

        $filters = $baseFilters;
        if (null !== $opts->authorId) {
            $filters[] = 'authorId = '.$opts->authorId;
        }
        if (null !== $opts->createdBefore) {
            $filters[] = 'createdAt < '.$opts->createdBefore;
        }
        if (null !== $opts->createdAfter) {
            $filters[] = 'createdAt > '.$opts->createdAfter;
        }

        try {
            $result = $this->client->index(self::INDEX_MESSAGES)->search($query, [
                'filter' => $filters,
                'limit' => $limit,
                'offset' => $offset,
                'attributesToHighlight' => ['text'],
                'highlightPreTag' => '<mark>',
                'highlightPostTag' => '</mark>',
                'sort' => ['createdAt:desc'],
            ]);

            /** @var array<int, array<string, mixed>> $hits */
            $hits = $result->getHits();
            $total = $result->getEstimatedTotalHits() ?? \count($hits);

            return [
                'hits' => $hits,
                'total' => (int) $total,
                'limit' => $limit,
                'offset' => $offset,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('meilisearch search failed', [
                'filters' => $filters,
                'error' => $e->getMessage(),
            ]);

            throw new SearchUnavailableException('Search backend unavailable.', 0, $e);
        }
    }
}
