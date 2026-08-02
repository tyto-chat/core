<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Async\DisconnectVoiceParticipantMessage;
use App\Entity\Channel;
use App\Entity\ChannelMember;
use App\Entity\Community;
use App\Entity\User;
use App\Enum\Channel\ChannelRole;
use App\Enum\User\UserRole;
use App\Repository\ChannelMemberRepository;
use App\Security\SecurityContext;
use App\Service\Channel\ChannelMembershipService;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Notification\NotificationServiceInterface;
use App\Service\Realtime\RealtimePublisherInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AllowMockObjectsWithoutExpectations]
class ChannelMembershipServiceTest extends TestCase
{
    private ChannelMemberRepository&MockObject $channelMemberRepository;
    private CommunityMembershipServiceInterface&MockObject $communityMembershipService;
    private NotificationServiceInterface&MockObject $notificationService;
    private RealtimePublisherInterface&MockObject $realtimePublisher;
    private MessageBusInterface&MockObject $messageBus;
    private EntityManagerInterface&MockObject $entityManager;
    private Security&MockObject $security;
    private ChannelMembershipService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->channelMemberRepository = $this->createMock(ChannelMemberRepository::class);
        $this->communityMembershipService = $this->createMock(CommunityMembershipServiceInterface::class);
        $this->notificationService = $this->createMock(NotificationServiceInterface::class);
        $this->realtimePublisher = $this->createMock(RealtimePublisherInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->messageBus->method('dispatch')->willReturnCallback(
            static fn (object $m): Envelope => new Envelope($m),
        );
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->security = $this->createMock(Security::class);

        $this->service = new ChannelMembershipService(
            new SecurityContext($this->security, $this->communityMembershipService),
            $this->communityMembershipService,
            $this->channelMemberRepository,
            $this->notificationService,
            $this->realtimePublisher,
            $this->messageBus,
        );
        $this->service->setEntityManager($this->entityManager);
        $this->service->setLogger(new NullLogger());
    }

    /** @return Channel&MockObject */
    private function channelInCommunity(Community $community): Channel
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getCommunity')->willReturn($community);

        return $channel;
    }

    public function testAddMemberThrowsWhenNoPermission(): void
    {
        $channel = $this->createMock(Channel::class);
        $user = $this->createMock(User::class);

        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->addMember($channel, $user);
    }

    public function testAddMemberThrowsWhenUserNotInCommunity(): void
    {
        $community = $this->createMock(Community::class);
        $channel = $this->channelInCommunity($community);
        $user = $this->createMock(User::class);

        // Caller passes authz (admin gets MODERATE via voter), but target user is not
        // a community member — invariant should fire instead of the auth gate.
        $this->security->method('isGranted')->willReturnMap([
            [\App\Security\Voter\ChannelVoter::MODERATE, $channel, true],
            [UserRole::Admin->value, null, true],
        ]);
        $this->communityMembershipService->method('isMember')->willReturn(false);

        $this->expectException(\App\Exception\Channel\UserNotCommunityMemberException::class);
        $this->service->addMember($channel, $user);
    }

    public function testAddMemberReturnsExistingMemberWhenRoleUnchanged(): void
    {
        $community = $this->createMock(Community::class);
        $channel = $this->channelInCommunity($community);
        $user = $this->createMock(User::class);
        $existing = (new ChannelMember())->setRole(ChannelRole::Member);

        $this->security->method('isGranted')->willReturn(true);
        $this->communityMembershipService->method('isMember')->willReturn(true);
        $this->channelMemberRepository->method('findOneByUserAndChannel')->willReturn($existing);

        $this->entityManager->expects(self::never())->method('persist');

        $result = $this->service->addMember($channel, $user, ChannelRole::Member);

        self::assertSame($existing, $result);
    }

    public function testAddMemberUpdatesRoleWhenDifferent(): void
    {
        $community = $this->createMock(Community::class);
        $channel = $this->channelInCommunity($community);
        $user = $this->createMock(User::class);
        $existing = (new ChannelMember())->setRole(ChannelRole::Member);

        $this->security->method('isGranted')->willReturn(true);
        $this->communityMembershipService->method('isMember')->willReturn(true);
        $this->channelMemberRepository->method('findOneByUserAndChannel')->willReturn($existing);

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $this->service->addMember($channel, $user, ChannelRole::Moderator);

        self::assertSame(ChannelRole::Moderator, $existing->getRole());
    }

    public function testAddMemberCreatesNewMemberAndSendsNotification(): void
    {
        $community = $this->createMock(Community::class);
        $community->method('getIdentifier')->willReturn('test');
        $channel = $this->channelInCommunity($community);
        $channel->method('getIdentifier')->willReturn('general');
        $user = $this->createMock(User::class);

        $this->security->method('isGranted')->willReturn(true);
        $this->communityMembershipService->method('isMember')->willReturn(true);
        $this->channelMemberRepository->method('findOneByUserAndChannel')->willReturn(null);

        $this->entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(ChannelMember::class));
        $this->entityManager->expects(self::once())->method('flush');
        $this->notificationService->expects(self::once())->method('new');

        $result = $this->service->addMember($channel, $user);

        self::assertSame(ChannelRole::Member, $result->getRole());
    }

    private function memberInCommunity(User $memberUser, ChannelRole $role = ChannelRole::Moderator): ChannelMember&MockObject
    {
        $community = $this->createMock(Community::class);
        $channel = $this->createMock(Channel::class);
        $channel->method('getCommunity')->willReturn($community);

        $member = $this->createMock(ChannelMember::class);
        $member->method('getUser')->willReturn($memberUser);
        $member->method('getRole')->willReturn($role);
        $member->method('getChannel')->willReturn($channel);

        return $member;
    }

    public function testRemoveMemberAllowsUserToRemoveThemselves(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);
        $member = $this->memberInCommunity($user);

        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')->willReturn(false); // not admin

        $this->entityManager->expects(self::once())->method('remove')->with($member);
        $this->entityManager->expects(self::once())->method('flush');

        $this->service->removeMember($member);
    }

    public function testRemoveMemberThrowsWhenNonAdminTriesToRemoveModerator(): void
    {
        $currentUser = $this->createMock(User::class);
        $currentUser->method('getId')->willReturn(99);

        $moderatorUser = $this->createMock(User::class);
        $moderatorUser->method('getId')->willReturn(5);

        $member = $this->memberInCommunity($moderatorUser);

        $this->security->method('getUser')->willReturn($currentUser);
        $this->security->method('isGranted')->willReturn(false); // not admin
        $this->communityMembershipService->method('isAdmin')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->removeMember($member);
    }

    public function testRemoveMemberAllowsAdminToRemoveModerator(): void
    {
        $admin = $this->createMock(User::class);
        $admin->method('getId')->willReturn(99);

        $moderatorUser = $this->createMock(User::class);
        $moderatorUser->method('getId')->willReturn(5);

        $member = $this->memberInCommunity($moderatorUser);

        $this->security->method('getUser')->willReturn($admin);
        $this->security->method('isGranted')->willReturn(true); // is admin

        $this->entityManager->expects(self::once())->method('remove')->with($member);
        $this->entityManager->expects(self::once())->method('flush');

        $this->service->removeMember($member);
    }

    public function testRemoveMemberPrivateChannelPublishesStructureAndRevokeEvent(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);

        $community = $this->createMock(Community::class);
        $community->method('getIdentifier')->willReturn('my-community');

        $channel = $this->createMock(Channel::class);
        $channel->method('getCommunity')->willReturn($community);
        $channel->method('isPrivate')->willReturn(true);
        $channel->method('getIdentifier')->willReturn('secret-channel');
        $channel->method('getId')->willReturn(12);

        $member = $this->createMock(ChannelMember::class);
        $member->method('getUser')->willReturn($user);
        $member->method('getRole')->willReturn(ChannelRole::Member);
        $member->method('getChannel')->willReturn($channel);

        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')->willReturn(false);

        $this->realtimePublisher->expects(self::once())->method('publishCommunityStructureChanged')->with($community);
        $this->realtimePublisher->expects(self::once())->method('publishUserEvent')->with(
            5,
            'channel.access.revoked',
            [
                'communityIdentifier' => 'my-community',
                'channelIdentifier' => 'secret-channel',
            ],
        );

        $this->messageBus->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn (object $m): bool => $m instanceof DisconnectVoiceParticipantMessage
                && 5 === $m->userId
                && 12 === $m->channelId
                && null === $m->communityIdentifier))
            ->willReturn(new Envelope(new DisconnectVoiceParticipantMessage(5, channelId: 12)));

        $this->service->removeMember($member);
    }

    public function testRemoveMemberPublicChannelPublishesStructureButNoRevokeEvent(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);

        $community = $this->createMock(Community::class);

        $channel = $this->createMock(Channel::class);
        $channel->method('getCommunity')->willReturn($community);
        $channel->method('isPrivate')->willReturn(false);

        $member = $this->createMock(ChannelMember::class);
        $member->method('getUser')->willReturn($user);
        $member->method('getRole')->willReturn(ChannelRole::Member);
        $member->method('getChannel')->willReturn($channel);

        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')->willReturn(false);

        $this->realtimePublisher->expects(self::once())->method('publishCommunityStructureChanged')->with($community);
        $this->realtimePublisher->expects(self::never())->method('publishUserEvent');

        $this->messageBus->expects(self::never())->method('dispatch')
            ->with(self::isInstanceOf(DisconnectVoiceParticipantMessage::class));

        $this->service->removeMember($member);
    }
}
