<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Dto\User\BlockRelations;
use App\Entity\User;
use App\Entity\UserBlock;
use App\Exception\User\BlockLimitReachedException;
use App\Exception\User\CannotBlockSelfException;
use App\Exception\User\CannotBlockStaffException;
use App\Exception\User\UserNotFoundException;
use App\Repository\UserBlockRepository;
use App\Repository\UserRepository;
use App\Security\SecurityContext;
use App\Service\AbstractDoctrineService;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\Realtime\StructureRealtimePublisherInterface;
use Symfony\Component\DependencyInjection\Attribute\Lazy;

class UserBlockService extends AbstractDoctrineService implements UserBlockServiceInterface
{
    public const int MAX_BLOCKS = 1000;

    public function __construct(
        private readonly SecurityContext $security,
        private readonly UserBlockRepository $blocks,
        private readonly UserRepository $users,
        private readonly CommunityMembershipServiceInterface $memberships,
        private readonly StructureRealtimePublisherInterface $realtimePublisher,
        #[Lazy]
        private readonly ConversationServiceInterface $conversations,
    ) {
    }

    #[\Override]
    public function block(int $targetUserId): UserBlock
    {
        $me = $this->security->currentUser();

        if ($me->getId() === $targetUserId) {
            throw new CannotBlockSelfException();
        }

        $target = $this->users->find($targetUserId) ?? throw new UserNotFoundException();

        if ($this->isStaffFor($target, $me)) {
            throw new CannotBlockStaffException();
        }

        $existing = $this->blocks->findOneByPair($me, $target);
        if (null !== $existing) {
            $this->publishChanged($me, $target);

            return $existing;
        }

        if ($this->blocks->countByBlocker($me) >= self::MAX_BLOCKS) {
            throw new BlockLimitReachedException();
        }

        $block = (new UserBlock())->setBlocker($me)->setBlocked($target);
        $this->persist($block);
        $this->flush();
        $this->publishChanged($me, $target);

        return $block;
    }

    #[\Override]
    public function unblock(int $targetUserId): void
    {
        $me = $this->security->currentUser();
        $target = $this->users->find($targetUserId);
        if (null === $target) {
            return;
        }

        $existing = $this->blocks->findOneByPair($me, $target);
        if (null !== $existing) {
            $this->removeAndFlush($existing);
        }
        $this->publishChanged($me, $target);
    }

    private function publishChanged(User $blocker, User $target): void
    {
        $this->realtimePublisher->publishUserEvent((int) $blocker->getId(), 'blocks.changed', ['userId' => (int) $target->getId()]);
        $this->conversations->publishPolicyForPair($blocker, $target);
    }

    #[\Override]
    public function listForCurrentUser(): array
    {
        $me = $this->security->currentUser();
        $rows = $this->blocks->findByBlocker($me);
        $enforced = $this->enforcedBlockedIdsByBlocker([(int) $me->getId()])[(int) $me->getId()] ?? [];

        return array_map(
            static fn (UserBlock $block): array => ['block' => $block, 'inEffect' => in_array($block->getBlocked()->getId(), $enforced, true)],
            $rows,
        );
    }

    #[\Override]
    public function statusForCurrentUser(int $targetUserId): array
    {
        $me = $this->security->currentUser();
        $target = $this->users->find($targetUserId) ?? throw new UserNotFoundException();

        if ($me->getId() === $targetUserId) {
            return ['blocked' => false, 'blockable' => false, 'inEffect' => false];
        }

        $staff = $this->isStaffFor($target, $me);
        $blocked = null !== $this->blocks->findOneByPair($me, $target);

        return ['blocked' => $blocked, 'blockable' => !$staff, 'inEffect' => $blocked && !$staff];
    }

    #[\Override]
    public function isStaffFor(User $subject, User $viewer): bool
    {
        if ($subject->isAdmin()) {
            return true;
        }

        $subjectId = (int) $subject->getId();

        return in_array((int) $viewer->getId(), $this->memberships->findStaffSharedPairs([$subjectId], [(int) $viewer->getId()])[$subjectId] ?? [], true);
    }

    #[\Override]
    public function enforcedRelations(User $user, array $otherIds): BlockRelations
    {
        $userId = (int) $user->getId();
        $pairs = $this->blocks->findPairsInvolving($userId, $otherIds);

        $heldTargets = [];
        $blockers = [];
        foreach ($pairs as $pair) {
            if ($pair['blockerId'] === $userId) {
                $heldTargets[] = $pair['blockedId'];
            } else {
                $blockers[] = $pair['blockerId'];
            }
        }

        $blockedByUser = [] === $heldTargets ? [] : ($this->enforcedBlockedIdsByBlocker([$userId])[$userId] ?? []);
        $blockedByUser = array_values(array_intersect($heldTargets, $blockedByUser));

        $blockingUser = $user->isAdmin() ? [] : array_values(array_unique($blockers));
        if ([] !== $blockingUser) {
            $staffFor = $this->memberships->findStaffSharedPairs([$userId], $blockingUser)[$userId] ?? [];
            $blockingUser = array_values(array_diff($blockingUser, $staffFor));
        }

        return new BlockRelations($blockedByUser, $blockingUser);
    }

    #[\Override]
    public function filterRecipientsNotBlocking(int $actorId, array $recipientIds): array
    {
        if ([] === $recipientIds) {
            return [];
        }

        $blockers = $this->blocks->findBlockerIdsAmong($actorId, $recipientIds);
        if ([] === $blockers) {
            return $recipientIds;
        }

        $actor = $this->users->find($actorId);
        if (null === $actor || $actor->isAdmin()) {
            return $recipientIds;
        }

        $ineffective = $this->memberships->findStaffSharedPairs([$actorId], $blockers)[$actorId] ?? [];
        $enforcing = array_diff($blockers, $ineffective);

        return array_values(array_diff($recipientIds, $enforcing));
    }

    #[\Override]
    public function enforcedBlockedIdsByBlocker(array $blockerIds): array
    {
        $held = $this->blocks->findBlockedIdsByBlockers($blockerIds);
        if ([] === $held) {
            return [];
        }

        $allBlocked = array_values(array_unique(array_merge(...array_values($held))));
        $adminIds = [];
        foreach ($this->users->findBy(['id' => $allBlocked]) as $user) {
            if ($user->isAdmin()) {
                $adminIds[] = (int) $user->getId();
            }
        }
        $staffPairs = $this->memberships->findStaffSharedPairs(array_values(array_diff($allBlocked, $adminIds)), array_keys($held));

        $result = [];
        foreach ($held as $blockerId => $blockedIds) {
            $enforced = array_values(array_filter(
                $blockedIds,
                static fn (int $blockedId): bool => !in_array($blockedId, $adminIds, true)
                    && !in_array($blockerId, $staffPairs[$blockedId] ?? [], true),
            ));
            if ([] !== $enforced) {
                $result[$blockerId] = $enforced;
            }
        }

        return $result;
    }

    #[\Override]
    public function deleteAllInvolving(User $user): void
    {
        $this->blocks->deleteAllInvolving($user);
    }
}
