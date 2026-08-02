<?php

declare(strict_types=1);

namespace App\Service\Moderation;

use App\Async\DisconnectVoiceParticipantMessage;
use App\Dto\Notification\CreateNotificationDto;
use App\Dto\Webhook\WebhookEventContext;
use App\Entity\Channel;
use App\Entity\ChannelMember;
use App\Entity\Community;
use App\Entity\ModerationAction;
use App\Entity\User;
use App\Enum\Community\CommunityRole;
use App\Enum\Moderation\ModerationActionType;
use App\Enum\Notification\NotificationType;
use App\Enum\User\UserRole;
use App\Exception\AccessDeniedException;
use App\Exception\Moderation\ImmuneTargetException;
use App\Exception\Moderation\ModerationActionNotFoundException;
use App\Repository\ModerationActionRepository;
use App\Security\SecurityContext;
use App\Security\Voter\ModerationVoter;
use App\Service\AbstractDoctrineService;
use App\Service\Channel\ChannelMembershipServiceInterface;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Notification\NotificationServiceInterface;
use App\Service\Realtime\StructureRealtimePublisherInterface;
use App\Service\Webhook\WebhookEmitterInterface;
use Symfony\Component\DependencyInjection\Attribute\Lazy;
use Symfony\Component\Messenger\MessageBusInterface;

class ModerationService extends AbstractDoctrineService implements ModerationServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly CommunityMembershipServiceInterface $communityMembership,
        private readonly ModerationActionRepository $moderationActionRepository,
        private readonly ChannelMembershipServiceInterface $channelMembershipService,
        #[Lazy]
        private readonly CommunityServiceInterface $communityService,
        private readonly NotificationServiceInterface $notificationService,
        private readonly WebhookEmitterInterface $webhookEmitter,
        private readonly StructureRealtimePublisherInterface $realtimePublisher,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[\Override]
    public function warn(Community $community, User $target, ?string $reason = null): ModerationAction
    {
        $actor = $this->security->currentUser();
        $this->security->throwAccessDeniedUnlessGranted(ModerationVoter::WARN, $community, 'You do not have permission to perform this moderation action.');
        $this->requireNotImmune($target, $community);

        $action = ModerationAction::warn($community, $target, $actor, $reason);
        $this->persist($action);
        $this->flush();

        $this->dispatchNotification($action);
        $this->emitWebhookEvent($action);

        return $action;
    }

    #[\Override]
    public function timeout(
        Community $community,
        User $target,
        ?string $reason,
        \DateTimeInterface $expiresAt,
        ?Channel $channel = null,
    ): ModerationAction {
        $actor = $this->security->currentUser();

        if (null !== $channel) {
            $this->security->throwAccessDeniedUnlessGranted(ModerationVoter::TIMEOUT, $channel, 'You do not have permission to perform this moderation action.');
        } else {
            $this->security->throwAccessDeniedUnlessGranted(ModerationVoter::TIMEOUT, $community, 'You do not have permission to perform this moderation action.');
        }

        $this->requireNotImmune($target, $community);

        $expiresAt = self::normalizeExpiry($expiresAt);

        $action = ModerationAction::timeout($community, $target, $actor, $reason, $expiresAt, $channel);
        $this->persist($action);
        $this->flush();

        $this->dispatchNotification($action);
        $this->emitWebhookEvent($action);
        $this->messageBus->dispatch(new DisconnectVoiceParticipantMessage(
            (int) $target->getId(),
            channelId: $channel?->getId(),
            communityIdentifier: null === $channel ? $community->getIdentifier() : null,
        ));

        return $action;
    }

    #[\Override]
    public function ban(
        Community $community,
        User $target,
        ?string $reason,
        ?\DateTimeInterface $expiresAt,
    ): ModerationAction {
        $actor = $this->security->currentUser();
        $this->security->throwAccessDeniedUnlessGranted(ModerationVoter::BAN, $community, 'Only community admins can issue community bans.');
        $this->requireNotImmune($target, $community);

        $expiresAt = null === $expiresAt ? null : self::normalizeExpiry($expiresAt);

        $action = ModerationAction::ban($community, $target, $actor, $reason, $expiresAt);

        $this->persist($action);
        $this->liftActiveTimeoutsInternal($community, $target, $actor);
        $this->flush();

        // Kick after persist so the row stamping the ban is committed first.
        $this->communityService->kickMember($community, $target);

        $this->dispatchNotification($action);
        $this->emitWebhookEvent($action);

        return $action;
    }

    #[\Override]
    public function serverBan(
        Community $community,
        User $target,
        ?string $reason,
        ?\DateTimeInterface $expiresAt,
    ): ModerationAction {
        $actor = $this->security->currentUser();
        $this->security->throwAccessDeniedUnlessGranted(ModerationVoter::SERVER_BAN, $community, 'Only global administrators can issue server-wide bans.');
        $this->requireNotImmuneGlobal($target);

        $expiresAt = null === $expiresAt ? null : self::normalizeExpiry($expiresAt);

        $action = ModerationAction::serverBan($community, $target, $actor, $reason, $expiresAt);

        $this->persist($action);
        $this->liftActiveTimeoutsInternal($community, $target, $actor);
        $this->flush();

        $this->dispatchNotification($action);
        $this->emitWebhookEvent($action);
        $this->realtimePublisher->publishUserEvent((int) $target->getId(), 'server.banned');
        $this->messageBus->dispatch(new DisconnectVoiceParticipantMessage((int) $target->getId()));

        return $action;
    }

    #[\Override]
    public function lift(ModerationAction $action): ModerationAction
    {
        $actor = $this->security->currentUser();
        $this->security->throwAccessDeniedUnlessGranted(ModerationVoter::LIFT, $action, 'You do not have permission to lift this action.');

        $action->lift($actor);

        $this->persist($action);
        $this->flush();

        $this->notifyLifted($action);

        return $action;
    }

    private function notifyLifted(ModerationAction $action): void
    {
        $notificationType = match ($action->getType()) {
            ModerationActionType::Ban => NotificationType::BanLifted,
            ModerationActionType::ServerBan => NotificationType::ServerBanLifted,
            default => NotificationType::TimeoutLifted,
        };

        $this->notificationService->new(new CreateNotificationDto(
            recipient: $action->getTargetUser(),
            community: $action->getCommunity(),
            communityIdentifier: $action->getCommunity()->getIdentifier() ?? '',
            type: $notificationType,
        ));
    }

    /** Doctrine DATETIME strips timezone on store/load — normalise to server-local time so the round-trip stays consistent. */
    private static function normalizeExpiry(\DateTimeInterface $when): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($when)->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    private function emitWebhookEvent(ModerationAction $action): void
    {
        $actor = $action->getActorUser();
        $this->webhookEmitter->emit('moderation.action', new WebhookEventContext(
            actor: $actor,
            data: [
                'communityId' => $action->getCommunity()->getId(),
                'actionType' => $action->getType()->value,
                'targetUserId' => $action->getTargetUser()->getId(),
                'moderatorId' => $actor->getId(),
                'reason' => $action->getReason(),
            ],
        ));
    }

    private function dispatchNotification(ModerationAction $action): void
    {
        $target = $action->getTargetUser();
        $community = $action->getCommunity();
        $channel = $action->getChannel();
        $actorName = $action->getActorUser()->getProfile()?->getName() ?? '';

        $this->notificationService->new(new CreateNotificationDto(
            recipient: $target,
            community: $community,
            communityIdentifier: $community->getIdentifier() ?? '',
            channelIdentifier: $channel?->getIdentifier() ?? '',
            type: NotificationType::from($action->getType()->value),
            authorName: $actorName,
            reason: $action->getReason(),
            expiresAt: $action->getExpiresAt(),
            moderationActionId: $action->getId(),
        ));
    }

    #[\Override]
    public function isTimedOut(Community $community, Channel $channel, User $user): bool
    {
        $communityWide = $this->moderationActionRepository->findActiveTimeoutInScope($community, $user, null);
        if (null !== $communityWide) {
            return true;
        }

        return null !== $this->moderationActionRepository->findActiveTimeoutInScope($community, $user, $channel);
    }

    #[\Override]
    public function isBanned(Community $community, User $user): bool
    {
        if (null !== $this->moderationActionRepository->findActiveServerBan($user)) {
            return true;
        }

        return null !== $this->moderationActionRepository->findActiveBanByUserAndCommunity($community, $user);
    }

    #[\Override]
    public function isServerBanned(User $user): bool
    {
        return null !== $this->moderationActionRepository->findActiveServerBan($user);
    }

    #[\Override]
    public function getActiveActions(Community $community, User $user): array
    {
        $actor = $this->security->currentUser();

        if ($actor === $user || $this->security->isCommunityModOrAdmin($community)) {
            return $this->moderationActionRepository->findActiveByUserAndCommunity($community, $user);
        }

        $membership = $this->getChannelModMembership($actor, $community);
        if (null === $membership) {
            throw new AccessDeniedException('You do not have permission to view this user\'s moderation status.');
        }

        $modChannelId = $membership->getChannel()->getId();
        $actions = $this->moderationActionRepository->findActiveByUserAndCommunity($community, $user);

        return array_values(array_filter(
            $actions,
            static fn (ModerationAction $a): bool => null !== $modChannelId && $a->getChannel()?->getId() === $modChannelId,
        ));
    }

    #[\Override]
    public function getLog(
        Community $community,
        int $page = 1,
        ?Channel $channelFilter = null,
        ?string $typeFilter = null,
        bool $activeOnly = false,
        ?User $targetUser = null,
    ): array {
        $actor = $this->security->currentUser();

        if ($this->security->isCommunityModOrAdmin($community)) {
            return $this->moderationActionRepository->findLogByCommunity($community, $page, 50, $channelFilter, $typeFilter, $activeOnly, $targetUser);
        }

        $channelMember = $this->getChannelModMembership($actor, $community);
        if (null !== $channelMember) {
            return $this->moderationActionRepository->findLogByCommunity($community, $page, 50, $channelMember->getChannel(), $typeFilter, $activeOnly, $targetUser);
        }

        throw new AccessDeniedException('You do not have permission to view the moderation log.');
    }

    #[\Override]
    public function countLog(
        Community $community,
        ?Channel $channelFilter = null,
        ?string $typeFilter = null,
        bool $activeOnly = false,
        ?User $targetUser = null,
    ): int {
        $actor = $this->security->currentUser();

        if ($this->security->isCommunityModOrAdmin($community)) {
            return $this->moderationActionRepository->countLogByCommunity($community, $channelFilter, $typeFilter, $activeOnly, $targetUser);
        }

        $channelMember = $this->getChannelModMembership($actor, $community);
        if (null !== $channelMember) {
            return $this->moderationActionRepository->countLogByCommunity($community, $channelMember->getChannel(), $typeFilter, $activeOnly, $targetUser);
        }

        throw new AccessDeniedException('You do not have permission to view the moderation log.');
    }

    #[\Override]
    public function getAction(int $id, Community $community): ModerationAction
    {
        $this->security->throwAccessDeniedUnlessAuthenticated();

        $action = $this->moderationActionRepository->findOneBy(['id' => $id, 'community' => $community]);
        if (null === $action) {
            throw new ModerationActionNotFoundException(sprintf('Moderation action %d not found.', $id));
        }

        $this->security->throwAccessDeniedUnlessGranted(ModerationVoter::VIEW, $action, 'You do not have permission to view this action.');

        return $action;
    }

    private function requireNotImmune(User $target, Community $community): void
    {
        $this->requireNotImmuneGlobal($target);

        $role = $this->communityMembership->findRole($target, $community);
        if (CommunityRole::Admin === $role) {
            throw new ImmuneTargetException('Community admins cannot be moderated.');
        }
    }

    private function requireNotImmuneGlobal(User $target): void
    {
        if ($target->isBot()) {
            throw new ImmuneTargetException('Bot users cannot be moderated.');
        }

        if ($target->hasRole(UserRole::Admin->value)) {
            throw new ImmuneTargetException('Global admins cannot be moderated.');
        }
    }

    private function getChannelModMembership(User $user, Community $community): ?ChannelMember
    {
        return $this->channelMembershipService->findModeratorMembershipInCommunity($user, $community);
    }

    private function liftActiveTimeoutsInternal(Community $community, User $target, User $actor): void
    {
        $timeouts = $this->moderationActionRepository->findActiveTimeoutsByUserAndCommunity($community, $target);
        $now = new \DateTimeImmutable();

        foreach ($timeouts as $timeout) {
            $timeout->setLiftedAt($now);
            $timeout->setLiftedBy($actor);
            $this->persist($timeout);
        }
    }
}
