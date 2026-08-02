<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Dto\Search\SearchOptions;
use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\Message;

interface SearchServiceInterface
{
    public function indexMessage(Message $message): void;

    /** @param Message[] $messages */
    public function indexMessages(array $messages): void;

    public function removeMessage(string $messageId): void;

    public function removeChannelDocuments(int $channelId): void;

    /**
     * @return array{hits: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function searchChannel(Channel $channel, string $query, ?SearchOptions $options = null): array;

    /**
     * @return array{hits: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function searchConversation(Conversation $conversation, string $query, ?SearchOptions $options = null): array;

    /**
     * Raw driver, no authz — caller must pass only channel ids the user may view.
     *
     * @param array<int, int> $channelIds
     *
     * @return array{hits: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function searchChannels(array $channelIds, string $query, ?SearchOptions $options = null): array;

    public function ensureIndexConfigured(): void;
}
