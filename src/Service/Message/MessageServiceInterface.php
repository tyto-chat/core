<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Dto\Message\UpdateMessageDto;
use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\MessagePage;
use App\Entity\User;
use App\Enum\Message\MessageKind;

interface MessageServiceInterface
{
    public const int MAX_PINNED_PER_CHANNEL = 50;

    /**
     * @param list<string> $attachmentIris
     */
    public function sendToChannel(Channel $channel, string $text, array $attachmentIris = [], MessageKind $kind = MessageKind::Standard): Message;

    /**
     * @param list<string> $attachmentIris
     */
    public function sendToConversation(Conversation $conversation, string $text, array $attachmentIris = []): Message;

    public function update(Message $message, UpdateMessageDto $updateMessageDto): Message;

    public function getById(string $uuid): Message;

    public function getForHistory(string $uuid): Message;

    public function delete(Message $message): void;

    /** @return Message[] chronological */
    public function getRootsByPage(MessagePage $page): array;

    public function hydrateText(Message $message): void;

    /** @internal search backfill — no authz, CLI/worker context */
    public function countIndexableMessages(): int;

    /**
     * @internal search backfill — no authz, CLI/worker context
     *
     * @return Message[]
     */
    public function findIndexableMessagesBatch(int $offset, int $limit): array;

    /**
     * @return Message[]
     */
    public function findPinnedForChannel(Channel $channel): array;

    public function pin(Message $message): Message;

    public function unpin(Message $message): Message;

    /**
     * @param list<string> $attachmentIris
     */
    public function reply(Message $root, string $text, array $attachmentIris = []): Message;

    /**
     * @return Message[]
     */
    public function findThreadReplies(Message $root, int $limit = 50, ?Message $before = null): array;

    /** No authz — caller must enforce. */
    public function countNewInConversationFor(
        Conversation $conversation,
        User $excluding,
        ?\DateTimeImmutable $since,
    ): int;

    /**
     * No authz — caller must enforce.
     *
     * @return array<int, int> conversation id → unread count
     */
    public function countUnreadPerConversationFor(User $user): array;
}
