<?php

declare(strict_types=1);

namespace App\Service\Conversation;

use App\Entity\Conversation;
use App\Entity\ConversationMember;
use App\Entity\MessagePage;
use App\Entity\User;
use App\Enum\Conversation\ConversationWrite;
use App\Enum\Conversation\ReadOnlyReason;

interface ConversationServiceInterface
{
    /**
     * @param User[] $otherParticipants
     */
    public function createOrFind(array $otherParticipants): Conversation;

    public function getByIdentifier(string $identifier): Conversation;

    public function readOnlyReason(Conversation $conversation, User $viewer): ?ReadOnlyReason;

    public function publishPolicyForPair(User $a, User $b): void;

    public function assertWritable(Conversation $conversation, ConversationWrite $write): void;

    public function assertCanUpload(Conversation $conversation): void;

    public function publishTyping(Conversation $conversation): void;

    /** @return Conversation[] */
    public function listForCurrentUser(): array;

    /** @return Conversation[] */
    public function listStartedForCurrentUser(): array;

    public function setMuted(Conversation $conversation, ?\DateTimeImmutable $mutedUntil): ConversationMember;

    public function markRead(Conversation $conversation): ConversationMember;

    public function markAllRead(): void;

    public function isMember(Conversation $conversation, User $user): bool;

    public function findLatestPage(Conversation $conversation): ?MessagePage;

    public function nextPageNumber(Conversation $conversation): int;

    public function getCurrentPage(Conversation $conversation): MessagePage;

    public function getPage(Conversation $conversation, int $pageNumber): MessagePage;

    /** @return MessagePage[] */
    public function getPages(Conversation $conversation): array;

    /** @return list<int> */
    public function findRecentPartnerUserIds(User $user, int $limit): array;
}
