<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Dto\Search\SearchOptions;
use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Service\Search\SearchServiceInterface;
use App\Utils\MessageDocumentBuilder;

/**
 * Test double for the search backend. Stores documents in a plain array so
 * functional tests can assert on what got indexed without hitting Meili.
 * Search is a naive case-insensitive substring match — good enough to verify
 * controller wiring, filters, and voter integration.
 */
final class InMemorySearchService implements SearchServiceInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $docs = [];

    public function ensureIndexConfigured(): void
    {
    }

    public function indexMessage(Message $message): void
    {
        if (!MessageDocumentBuilder::isIndexable($message)) {
            $this->removeMessage($message->getId());

            return;
        }

        $this->docs[$message->getId()] = MessageDocumentBuilder::build($message);
    }

    public function indexMessages(array $messages): void
    {
        foreach ($messages as $message) {
            $this->indexMessage($message);
        }
    }

    public function removeMessage(string $messageId): void
    {
        unset($this->docs[$messageId]);
    }

    public function removeChannelDocuments(int $channelId): void
    {
        $this->docs = array_filter(
            $this->docs,
            static fn (array $d): bool => ($d['channelId'] ?? null) !== $channelId,
        );
    }

    public function searchChannel(Channel $channel, string $query, ?SearchOptions $options = null): array
    {
        return $this->filterAndPaginate(
            fn (array $d): bool => ($d['channelId'] ?? null) === $channel->getId(),
            $query,
            $options ?? new SearchOptions(),
        );
    }

    public function searchConversation(Conversation $conversation, string $query, ?SearchOptions $options = null): array
    {
        return $this->filterAndPaginate(
            fn (array $d): bool => ($d['conversationId'] ?? null) === $conversation->getId(),
            $query,
            $options ?? new SearchOptions(),
        );
    }

    public function searchChannels(array $channelIds, string $query, ?SearchOptions $options = null): array
    {
        return $this->filterAndPaginate(
            static fn (array $d): bool => \in_array($d['channelId'] ?? null, $channelIds, true),
            $query,
            $options ?? new SearchOptions(),
        );
    }

    /**
     * Test-only: seed a raw document, bypassing MessageDocumentBuilder. Lets
     * functional tests simulate stale/forged index rows.
     *
     * @param array<string, mixed> $doc
     */
    public function injectDocument(array $doc): void
    {
        $this->docs[(string) $doc['id']] = $doc;
    }

    /**
     * @param callable(array<string, mixed>): bool $scope
     *
     * @return array{hits: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    private function filterAndPaginate(callable $scope, string $query, SearchOptions $opts): array
    {
        $needle = mb_strtolower(trim($query));
        $matches = [];

        foreach ($this->docs as $doc) {
            if (!$scope($doc)) {
                continue;
            }
            if (null !== $opts->authorId && ($doc['authorId'] ?? null) !== $opts->authorId) {
                continue;
            }
            if (null !== $opts->createdBefore && (int) ($doc['createdAt'] ?? 0) >= $opts->createdBefore) {
                continue;
            }
            if (null !== $opts->createdAfter && (int) ($doc['createdAt'] ?? 0) <= $opts->createdAfter) {
                continue;
            }

            $haystack = mb_strtolower((string) ($doc['text'] ?? ''));
            if ('' !== $needle && !str_contains($haystack, $needle)) {
                continue;
            }

            $highlighted = $doc;
            $highlighted['_formatted'] = ['text' => str_ireplace($query, '<mark>'.$query.'</mark>', (string) ($doc['text'] ?? ''))];
            $matches[] = $highlighted;
        }

        usort($matches, static fn (array $a, array $b): int => (int) ($b['createdAt'] ?? 0) <=> (int) ($a['createdAt'] ?? 0));

        $total = \count($matches);
        $hits = \array_slice($matches, $opts->offset, $opts->limit);

        return [
            'hits' => $hits,
            'total' => $total,
            'limit' => $opts->limit,
            'offset' => $opts->offset,
        ];
    }
}
