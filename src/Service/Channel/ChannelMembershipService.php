<?php

declare(strict_types=1);

namespace App\Service\Channel;

use App\Async\DisconnectVoiceParticipantMessage;
use App\Dto\Notification\CreateNotificationDto;
use App\Entity\Channel;
use App\Entity\ChannelMember;
use App\Entity\Community;
use App\Entity\User;
use App\Enum\Channel\ChannelRole;
use App\Enum\Notification\NotificationType;
use App\Exception\Channel\ChannelMemberNotFoundException;
use App\Exception\Channel\UserNotCommunityMemberException;
use App\Repository\ChannelMemberRepository;
use App\Security\SecurityContext;
use App\Security\Voter\ChannelVoter;
use App\Service\AbstractDoctrineService;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Notification\NotificationServiceInterface;
use App\Service\Realtime\StructureRealtimePublisherInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class ChannelMembershipService extends AbstractDoctrineService implements ChannelMembershipServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly CommunityMembershipServiceInterface $communityMembership,
        private readonly ChannelMemberRepository $channelMemberRepository,
        private readonly NotificationServiceInterface $notificationService,
        private readonly StructureRealtimePublisherInterface $realtimePublisher,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[\Override]
    public function getMembers(Channel $channel): array
    {
        $this->security->throwAccessDeniedUnlessGranted(ChannelVoter::VIEW_MEMBERS, $channel, 'You do not have permission to view members of this channel.');

        return $this->channelMemberRepository->findByChannel($channel);
    }

    #[\Override]
    public function addMember(Channel $channel, User $user, ChannelRole $role = ChannelRole::Member): ChannelMember
    {
        $this->security->throwAccessDeniedUnlessGranted(ChannelVoter::MODERATE, $channel, "You don't have permission to add members to this channel.");

        $community = $channel->getCommunity();
        if (null === $community || !$this->communityMembership->isMember($user, $community)) {
            throw new UserNotCommunityMemberException();
        }

        $existing = $this->channelMemberRepository->findOneByUserAndChannel($user, $channel);
        if (null !== $existing) {
            if ($existing->getRole() === $role) {
                return $existing;
            }
            $existing->setRole($role);
            $saved = $this->save($existing);

            $this->publishAccessGranted($community, $channel, $user);

            return $saved;
        }

        $channelMember = new ChannelMember();
        $channelMember->setUser($user);
        $channelMember->setChannel($channel);
        $channelMember->setRole($role);
        $channelMember = $this->save($channelMember);

        $this->notificationService->new(new CreateNotificationDto(
            recipient: $user,
            community: $community,
            channelIdentifier: (string) $channel->getIdentifier(),
            communityIdentifier: (string) $community->getIdentifier(),
            type: ChannelRole::Moderator === $role ? NotificationType::ChannelModerator : NotificationType::ChannelAccess,
        ));

        $this->publishAccessGranted($community, $channel, $user);

        return $channelMember;
    }

    private function publishAccessGranted(Community $community, Channel $channel, User $user): void
    {
        $this->realtimePublisher->publishCommunityStructureChanged($community);
        if ($channel->isPrivate()) {
            $this->realtimePublisher->publishUserEvent(
                (int) $user->getId(),
                'channel.access.granted',
                [
                    'communityIdentifier' => (string) $community->getIdentifier(),
                    'channelIdentifier' => (string) $channel->getIdentifier(),
                ],
            );
        }
    }

    #[\Override]
    public function getMember(Channel $channel, User $user): ChannelMember
    {
        $this->security->throwAccessDeniedUnlessGranted(ChannelVoter::VIEW_MEMBERS, $channel, 'You do not have permission to view members of this channel.');

        $member = $this->channelMemberRepository->findOneByUserAndChannel($user, $channel);
        if (null === $member) {
            throw new ChannelMemberNotFoundException(sprintf('User "%d" is not a member of channel "%s".', $user->getId(), $channel->getIdentifier()));
        }

        return $member;
    }

    #[\Override]
    public function findChannelMemberRole(User $user, Channel $channel): ?ChannelRole
    {
        return $this->channelMemberRepository->findOneByUserAndChannel($user, $channel)?->getRole();
    }

    #[\Override]
    public function findChannelMemberRolesInCommunity(User $user, Community $community): array
    {
        return $this->channelMemberRepository->findRolesForUserInCommunity($user, $community);
    }

    #[\Override]
    public function isChannelMember(User $user, Channel $channel): bool
    {
        return $this->channelMemberRepository->isChannelMember($user, $channel);
    }

    #[\Override]
    public function isChannelModerator(User $user, Channel $channel): bool
    {
        $member = $this->channelMemberRepository->findOneByUserAndChannel($user, $channel);

        return null !== $member && ChannelRole::Moderator === $member->getRole();
    }

    #[\Override]
    public function findModeratorMembershipInCommunity(User $user, Community $community): ?ChannelMember
    {
        return $this->channelMemberRepository->findModeratorMembershipInCommunity($user, $community);
    }

    #[\Override]
    public function removeAllForUserInCommunity(User $user, Community $community): void
    {
        $this->channelMemberRepository->deleteForUserInCommunity($user, $community);
    }

    #[\Override]
    public function removeMember(ChannelMember $channelMember): void
    {
        $currentUser = $this->security->getUser();
        $channel = $channelMember->getChannel();
        $isSelf = $currentUser?->getId() === $channelMember->getUser()->getId();
        $isCommunityAdmin = $this->security->isCommunityAdmin($channel->getCommunity());
        $isChannelModerator = $this->security->isGranted(ChannelVoter::MODERATE, $channel);

        $canRemove = $isSelf
            || $isCommunityAdmin
            || ($isChannelModerator && $channel->isPrivate());

        $this->security->throwAccessDeniedIf(!$canRemove, "You don't have permission to remove this member.");
        $this->security->throwAccessDeniedIf(
            !$isSelf && !$isCommunityAdmin && ChannelRole::Moderator === $channelMember->getRole(),
            'Channel moderators cannot be removed by other moderators.',
        );

        $removedUser = $channelMember->getUser();
        $isPrivate = $channel->isPrivate();
        $this->removeAndFlush($channelMember);
        $community = $channel->getCommunity();
        if (null !== $community) {
            $this->realtimePublisher->publishCommunityStructureChanged($community);
            if ($isPrivate) {
                $this->realtimePublisher->publishUserEvent(
                    (int) $removedUser->getId(),
                    'channel.access.revoked',
                    [
                        'communityIdentifier' => (string) $community->getIdentifier(),
                        'channelIdentifier' => (string) $channel->getIdentifier(),
                    ],
                );
                $this->messageBus->dispatch(new DisconnectVoiceParticipantMessage(
                    (int) $removedUser->getId(),
                    channelId: $channel->getId(),
                ));
            }
        }
    }

    #[\Override]
    public function updateMemberRole(ChannelMember $channelMember, ChannelRole $role): ChannelMember
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($channelMember->getChannel()->getCommunity(), "You don't have permission to update member roles.");

        $previousRole = $channelMember->getRole();
        $channelMember->setRole($role);
        $channelMember = $this->save($channelMember);

        if (ChannelRole::Moderator === $role && ChannelRole::Moderator !== $previousRole) {
            $channel = $channelMember->getChannel();
            $community = $channel->getCommunity();

            $this->notificationService->new(new CreateNotificationDto(
                recipient: $channelMember->getUser(),
                community: $community,
                channelIdentifier: (string) $channel->getIdentifier(),
                communityIdentifier: (string) $community?->getIdentifier(),
                type: NotificationType::ChannelModerator,
            ));
        }

        return $channelMember;
    }

    #[\Override]
    public function getMemberUserIds(Channel $channel): array
    {
        return array_map(
            static fn (ChannelMember $m): int => (int) $m->getUser()->getId(),
            $this->channelMemberRepository->findByChannel($channel),
        );
    }
}
