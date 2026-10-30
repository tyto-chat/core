<?php

declare(strict_types=1);

namespace App\Service\Conversation;

use App\Dto\Conversation\ConversationPreflightResult;
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
    public function createOrFind(array $otherParticipants, bool $encrypted = false): Conversation;

    /**
     * @param User[] $otherParticipants
     */
    public function preflight(array $otherParticipants): ConversationPreflightResult;

    public function getByIdentifier(string $identifier): Conversation;

    public function readOnlyReason(Conversation $conversation, User $viewer): ?ReadOnlyReason;

    /** @return array<string, array<int, ?ReadOnlyReason>> read-only reason per conversation identifier and member id */
    public function policySnapshotForPair(User $a, User $b): array;

    /** @param array<string, array<int, ?ReadOnlyReason>> $before */
    public function publishPolicyChangesForPair(User $a, User $b, array $before): void;

    /** @return array<string, array<int, ?ReadOnlyReason>> read-only reason per plain conversation identifier and member id */
    public function policySnapshotForUser(User $user): array;

    /** @param array<string, array<int, ?ReadOnlyReason>> $before */
    public function publishPolicyChangesForUser(User $user, array $before): void;

    public function publishServerPolicyChange(): void;

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

    /** @internal No authz — caller must gate. */
    public function sharesEncryptedConversation(User $a, User $b): bool;

    /** @return int[] */
    public function findEncryptedIds(): array;

    /** @return list<Conversation> encrypted conversations the user takes part in */
    public function findEncryptedForUser(User $user): array;

    public function findLatestPage(Conversation $conversation): ?MessagePage;

    public function nextPageNumber(Conversation $conversation): int;

    public function getCurrentPage(Conversation $conversation): MessagePage;

    public function getPage(Conversation $conversation, int $pageNumber): MessagePage;

    /** @return MessagePage[] */
    public function getPages(Conversation $conversation): array;

    /** @return list<int> */
    public function findRecentPartnerUserIds(User $user, int $limit): array;
}
