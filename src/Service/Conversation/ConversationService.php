<?php

declare(strict_types=1);

namespace App\Service\Conversation;

use App\Entity\Conversation;
use App\Entity\ConversationMember;
use App\Entity\MessagePage;
use App\Entity\User;
use App\Enum\Conversation\ConversationWrite;
use App\Enum\Conversation\ReadOnlyReason;
use App\Exception\Conversation\ConversationBlockedException;
use App\Exception\Conversation\ConversationNotFoundException;
use App\Exception\Conversation\ConversationReadOnlyException;
use App\Exception\Conversation\EmptyMemberListException;
use App\Exception\Conversation\NoSharedCommunityException;
use App\Exception\Conversation\NotMemberException;
use App\Exception\Message\MessagePageNotFoundException;
use App\Repository\ConversationMemberRepository;
use App\Repository\ConversationRepository;
use App\Repository\MessagePageRepository;
use App\Security\SecurityContext;
use App\Security\Voter\ConversationVoter;
use App\Service\AbstractDoctrineService;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Message\MessageServiceInterface;
use App\Service\Notification\NotificationServiceInterface;
use App\Service\Realtime\RealtimePublisherInterface;
use App\Service\User\UserBlockServiceInterface;
use App\ValueObject\User\BlockRelations;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Lazy;

class ConversationService extends AbstractDoctrineService implements ConversationServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly CommunityMembershipServiceInterface $communityMembership,
        private readonly ConversationRepository $conversationRepository,
        private readonly ConversationMemberRepository $conversationMemberRepository,
        private readonly MessagePageRepository $messagePageRepository,
        #[Lazy]
        private readonly MessageServiceInterface $messageService,
        private readonly ManagerRegistry $managerRegistry,
        private readonly NotificationServiceInterface $notifications,
        private readonly UserBlockServiceInterface $userBlocks,
        private readonly RealtimePublisherInterface $publisher,
    ) {
    }

    #[\Override]
    public function findRecentPartnerUserIds(User $user, int $limit): array
    {
        return $this->conversationMemberRepository->findRecentPartnerUserIds($user, $limit);
    }

    private function attachUnreadCount(Conversation $conversation, User $caller): Conversation
    {
        $member = $this->conversationMemberRepository->findForUser($conversation, $caller);
        if (null === $member) {
            $conversation->setUnreadCount(0);

            return $conversation;
        }

        $conversation->setUnreadCount(
            $this->messageService->countNewInConversationFor(
                $conversation,
                $caller,
                $member->getLastReadAt(),
            ),
        );

        return $conversation;
    }

    /**
     * @param User[] $otherParticipants
     */
    #[\Override]
    public function createOrFind(array $otherParticipants): Conversation
    {
        $caller = $this->security->currentUser();
        $others = $this->normalizeOtherParticipants($otherParticipants, $caller);
        if ([] === $others) {
            throw new EmptyMemberListException();
        }

        if (!$this->security->isAdmin()) {
            foreach ($others as $participant) {
                if (!$this->communityMembership->existsSharedCommunity($caller, $participant)) {
                    throw new NoSharedCommunityException(sprintf('No shared community with user %d.', (int) $participant->getId()));
                }
            }
        }

        $callerId = $caller->getId();
        \assert(null !== $callerId);
        $ids = array_map(static fn (User $u): int => (int) $u->getId(), $others);
        $ids[] = $callerId;
        $hash = Conversation::hashParticipants($ids);

        $existing = $this->conversationRepository->findOneByParticipantsHash($hash);
        if (null !== $existing) {
            return $this->attachReadOnly($existing, $caller);
        }

        $relations = $this->userBlocks->enforcedRelations($caller, array_values(array_map(static fn (User $u): int => (int) $u->getId(), $others)));
        foreach ($ids as $id) {
            if ($id !== $callerId && $relations->hasAnyWith($id)) {
                throw new ConversationBlockedException();
            }
        }

        $conversation = new Conversation();
        $conversation->setParticipantsHash($hash);

        foreach ([$caller, ...$others] as $participant) {
            $member = (new ConversationMember())->setUser($participant);
            $conversation->addMember($member);
        }

        $this->persist($conversation);
        try {
            $this->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Lost the find→insert race; the EM closed on the failed flush — reset before re-querying.
            $this->managerRegistry->resetManager();
            $existing = $this->conversationRepository->findOneByParticipantsHash($hash);
            if (null === $existing) {
                throw $e;
            }

            return $this->attachReadOnly($existing, $caller);
        }

        return $conversation;
    }

    #[\Override]
    public function getByIdentifier(string $identifier): Conversation
    {
        $conversation = $this->conversationRepository->findOneByIdentifier($identifier);
        if (null === $conversation) {
            throw new ConversationNotFoundException(sprintf('Conversation "%s" not found.', $identifier));
        }

        // No ROLE_ADMIN bypass — DMs are strictly participant-only.
        $this->security->throwAccessDeniedUnlessGranted(ConversationVoter::VIEW, $conversation, 'You are not a member of this conversation.');

        $caller = $this->security->getUser();
        \assert($caller instanceof User);

        return $this->attachReadOnly($this->attachUnreadCount($conversation, $caller), $caller);
    }

    #[\Override]
    public function readOnlyReason(Conversation $conversation, User $viewer): ?ReadOnlyReason
    {
        $otherId = $this->soleCounterpartId($conversation, $viewer);
        if (null === $otherId) {
            return null;
        }

        return $this->reasonFrom($this->userBlocks->enforcedRelations($viewer, [$otherId]), $otherId);
    }

    #[\Override]
    public function policySnapshotForPair(User $a, User $b): ?array
    {
        $conversation = $this->findPairConversation($a, $b);
        if (null === $conversation) {
            return null;
        }

        return [
            (int) $a->getId() => $this->readOnlyReason($conversation, $a),
            (int) $b->getId() => $this->readOnlyReason($conversation, $b),
        ];
    }

    #[\Override]
    public function publishPolicyChangesForPair(User $a, User $b, ?array $before): void
    {
        $conversation = $this->findPairConversation($a, $b);
        if (null === $conversation || null === $before) {
            return;
        }

        foreach ([$a, $b] as $member) {
            $reason = $this->readOnlyReason($conversation, $member);
            if ($reason === ($before[(int) $member->getId()] ?? null)) {
                continue;
            }
            $this->publisher->publishConversationPolicy((int) $member->getId(), $conversation->getIdentifier(), null !== $reason, $reason);
        }
    }

    private function findPairConversation(User $a, User $b): ?Conversation
    {
        return $this->conversationRepository->findOneByParticipantsHash(
            Conversation::hashParticipants([(int) $a->getId(), (int) $b->getId()]),
        );
    }

    #[\Override]
    public function assertWritable(Conversation $conversation, ConversationWrite $write): void
    {
        if (ConversationWrite::Unreact === $write || ConversationWrite::DeleteMessage === $write) {
            return;
        }

        if (null !== $this->readOnlyReason($conversation, $this->security->currentUser())) {
            throw new ConversationReadOnlyException();
        }
    }

    #[\Override]
    public function assertCanUpload(Conversation $conversation): void
    {
        $this->security->throwAccessDeniedUnlessGranted(ConversationVoter::WRITE, $conversation, 'You must be an active member of this conversation to upload attachments.');
        $this->assertWritable($conversation, ConversationWrite::Attach);
    }

    #[\Override]
    public function publishTyping(Conversation $conversation): void
    {
        $this->assertWritable($conversation, ConversationWrite::Typing);

        $user = $this->security->currentUser();
        $this->publisher->publishConversationTyping($conversation, (int) $user->getId(), $user->getProfile()?->getName() ?? 'Unknown');
    }

    private function attachReadOnly(Conversation $conversation, User $viewer): Conversation
    {
        $reason = $this->readOnlyReason($conversation, $viewer);
        $conversation->setReadOnly(null !== $reason);
        $conversation->setReadOnlyReason($reason);

        return $conversation;
    }

    private function soleCounterpartId(Conversation $conversation, User $viewer): ?int
    {
        $members = $conversation->getMembers();
        if (2 !== $members->count()) {
            return null;
        }

        $viewerId = $viewer->getId();
        $ids = [];
        foreach ($members as $member) {
            $ids[] = $member->getUserId();
        }
        if (!in_array($viewerId, $ids, true)) {
            return null;
        }

        foreach ($ids as $id) {
            if (null !== $id && $id !== $viewerId) {
                return $id;
            }
        }

        return null;
    }

    private function reasonFrom(BlockRelations $relations, int $otherId): ?ReadOnlyReason
    {
        if ($relations->blocks($otherId)) {
            return ReadOnlyReason::Blocked;
        }
        if ($relations->isBlockedBy($otherId)) {
            return ReadOnlyReason::Unavailable;
        }

        return null;
    }

    #[\Override]
    public function listForCurrentUser(): array
    {
        return $this->listFor(startedOnly: false);
    }

    #[\Override]
    public function listStartedForCurrentUser(): array
    {
        return $this->listFor(startedOnly: true);
    }

    /** @return Conversation[] */
    private function listFor(bool $startedOnly): array
    {
        $caller = $this->security->currentUser();

        $conversations = $this->conversationMemberRepository->findConversationsForUser($caller, $startedOnly);
        $unreadByConversation = $this->messageService->countUnreadPerConversationFor($caller);
        $counterparts = [];
        foreach ($conversations as $conversation) {
            $conversation->setUnreadCount($unreadByConversation[(int) $conversation->getId()] ?? 0);
            $otherId = $this->soleCounterpartId($conversation, $caller);
            if (null !== $otherId) {
                $counterparts[(int) $conversation->getId()] = $otherId;
            }
        }

        if ([] === $counterparts) {
            return $conversations;
        }

        $relations = $this->userBlocks->enforcedRelations($caller, array_values(array_unique($counterparts)));
        foreach ($conversations as $conversation) {
            $otherId = $counterparts[(int) $conversation->getId()] ?? null;
            if (null === $otherId) {
                continue;
            }
            $reason = $this->reasonFrom($relations, $otherId);
            $conversation->setReadOnly(null !== $reason);
            $conversation->setReadOnlyReason($reason);
        }

        return $conversations;
    }

    #[\Override]
    public function setMuted(Conversation $conversation, ?\DateTimeImmutable $mutedUntil): ConversationMember
    {
        $caller = $this->security->currentUser();
        $member = $this->conversationMemberRepository->findForUser($conversation, $caller);
        if (null === $member) {
            throw new NotMemberException();
        }

        $member->setMutedUntil($mutedUntil);

        return $this->save($member);
    }

    #[\Override]
    public function markRead(Conversation $conversation): ConversationMember
    {
        $caller = $this->security->currentUser();
        $member = $this->conversationMemberRepository->findForUser($conversation, $caller);
        if (null === $member) {
            throw new NotMemberException();
        }

        $member->setLastReadAt(new \DateTimeImmutable());
        $member = $this->save($member);

        $this->notifications->markConversationAsRead($conversation);

        return $member;
    }

    #[\Override]
    public function markAllRead(): void
    {
        $caller = $this->security->currentUser();

        $this->conversationMemberRepository->markAllReadForUser($caller, new \DateTimeImmutable());
    }

    #[\Override]
    public function isMember(Conversation $conversation, User $user): bool
    {
        return null !== $this->conversationMemberRepository->findForUser($conversation, $user);
    }

    #[\Override]
    public function findLatestPage(Conversation $conversation): ?MessagePage
    {
        return $this->messagePageRepository->findLatestForConversation($conversation);
    }

    #[\Override]
    public function nextPageNumber(Conversation $conversation): int
    {
        return $this->messagePageRepository->nextPageNumberForConversation($conversation);
    }

    #[\Override]
    public function getCurrentPage(Conversation $conversation): MessagePage
    {
        $this->security->throwAccessDeniedUnlessGranted(ConversationVoter::VIEW, $conversation, 'You are not a member of this conversation.');

        $page = $this->findLatestPage($conversation);
        if (null === $page) {
            // See the channel twin: the response is a MessagePage resource, so
            // a fresh conversation needs a real row rather than a placeholder.
            $page = (new MessagePage())->setConversation($conversation);
            $this->save($page);
        }

        return $this->hydratePage($page);
    }

    #[\Override]
    public function getPage(Conversation $conversation, int $pageNumber): MessagePage
    {
        $this->security->throwAccessDeniedUnlessGranted(ConversationVoter::VIEW, $conversation, 'You are not a member of this conversation.');

        $page = $this->messagePageRepository->findByConversationAndPageNumber($conversation, $pageNumber);
        if (null === $page) {
            throw new MessagePageNotFoundException(sprintf('Page %d not found for conversation %d.', $pageNumber, (int) $conversation->getId()));
        }

        return $this->hydratePage($page);
    }

    /** @return MessagePage[] */
    #[\Override]
    public function getPages(Conversation $conversation): array
    {
        $this->security->throwAccessDeniedUnlessGranted(ConversationVoter::VIEW, $conversation, 'You are not a member of this conversation.');

        return $this->messagePageRepository->findAllForConversation($conversation);
    }

    private function hydratePage(MessagePage $page): MessagePage
    {
        $messages = $this->messageService->getRootsByPage($page);
        foreach ($messages as $message) {
            $this->messageService->hydrateText($message);
        }
        $page->setHydratedMessages($messages);

        return $page;
    }

    /**
     * @param User[] $others
     *
     * @return User[] unique others (caller excluded if accidentally included)
     */
    private function normalizeOtherParticipants(array $others, User $caller): array
    {
        $unique = [];
        foreach ($others as $participant) {
            $id = $participant->getId();
            if (null === $id || $id === $caller->getId()) {
                continue;
            }
            $unique[$id] = $participant;
        }

        return array_values($unique);
    }
}
