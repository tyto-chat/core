<?php

declare(strict_types=1);

namespace App\Service\Conversation;

use App\Entity\Conversation;
use App\Entity\ConversationMember;
use App\Entity\MessagePage;
use App\Entity\User;

interface ConversationServiceInterface
{
    /**
     * @param User[] $otherParticipants
     */
    public function createOrFind(array $otherParticipants): Conversation;

    public function getByIdentifier(string $identifier): Conversation;

    /** @return Conversation[] */
    public function listForCurrentUser(): array;

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
}
