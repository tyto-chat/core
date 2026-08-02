<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\User;
use App\Enum\Channel\ChannelType;
use App\Enum\User\UserRole;
use App\Exception\Channel\NotAnAudioChannelException;
use App\Exception\Channel\VoiceDisabledException;
use App\Security\SecurityContext;
use App\Service\Channel\ChannelAudioService;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Presence\PresenceServiceInterface;
use App\Service\Realtime\RealtimePublisherInterface;
use App\Service\User\UserServiceInterface;
use App\Service\Voice\VoiceServiceInterface;
use App\Tests\Stub\InMemoryChannelParticipantStore;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AllowMockObjectsWithoutExpectations]
class ChannelAudioServiceTest extends TestCase
{
    private InMemoryChannelParticipantStore $participantStore;
    private RealtimePublisherInterface&MockObject $publisher;
    private VoiceServiceInterface&MockObject $voiceService;
    private PresenceServiceInterface&MockObject $presenceService;
    private CommunityMembershipServiceInterface&MockObject $communityMembershipService;
    private Security&MockObject $security;
    private UserServiceInterface&MockObject $userService;
    private ChannelServiceInterface&MockObject $channelService;
    private ChannelAudioService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->participantStore = new InMemoryChannelParticipantStore();
        $this->publisher = $this->createMock(RealtimePublisherInterface::class);
        $this->voiceService = $this->createMock(VoiceServiceInterface::class);
        $this->presenceService = $this->createMock(PresenceServiceInterface::class);
        $this->communityMembershipService = $this->createMock(CommunityMembershipServiceInterface::class);
        $this->security = $this->createMock(Security::class);
        $this->userService = $this->createMock(UserServiceInterface::class);
        $this->channelService = $this->createMock(ChannelServiceInterface::class);

        $this->service = new ChannelAudioService(
            new SecurityContext($this->security, $this->communityMembershipService),
            $this->participantStore,
            $this->publisher,
            $this->voiceService,
            $this->presenceService,
            voiceEnabled: true,
            userService: $this->userService,
            channelService: $this->channelService,
        );
    }

    public function testGetParticipantsThrowsWhenNotAuthenticated(): void
    {
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->getParticipants($this->createMock(Channel::class));
    }

    public function testGetParticipantsDelegatesToStoreWhenAuthenticated(): void
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(10);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);
        $this->security->method('isGranted')->willReturn(true);
        $this->participantStore->save($user, $channel, 'user-7');

        $participants = $this->service->getParticipants($channel);

        self::assertCount(1, $participants);
        self::assertSame(7, $participants[0]->getUserId());
    }

    public function testGetParticipantsThrowsWhenChannelViewDenied(): void
    {
        $channel = $this->createMock(Channel::class);
        $this->security->method('isGranted')->willReturnCallback(
            static fn (string $attribute): bool => UserRole::User->value === $attribute,
        );

        $this->expectException(AccessDeniedException::class);
        $this->service->getParticipants($channel);
    }

    public function testJoinAudioChannelThrowsWhenNotAuthenticated(): void
    {
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->joinAudioChannelAsCurrentUser($this->createMock(Channel::class));
    }

    public function testJoinAudioChannelThrowsWhenChannelViewDenied(): void
    {
        $user = $this->createMock(User::class);
        $channel = $this->createMock(Channel::class);

        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')->willReturnCallback(
            static fn (string $attribute): bool => UserRole::User->value === $attribute,
        );

        $this->expectException(AccessDeniedException::class);
        $this->service->joinAudioChannelAsCurrentUser($channel);
    }

    public function testJoinAudioChannelClearsAuthzWhenChannelViewGranted(): void
    {
        $user = $this->createMock(User::class);
        $channel = $this->createMock(Channel::class);
        $channel->method('getType')->willReturn(ChannelType::Text);

        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')->willReturn(true);

        $this->expectException(NotAnAudioChannelException::class);
        $this->service->joinAudioChannelAsCurrentUser($channel);
    }

    public function testJoinAudioChannelThrowsWhenVoiceDisabled(): void
    {
        $service = new ChannelAudioService(
            new SecurityContext($this->security, $this->communityMembershipService),
            $this->participantStore,
            $this->publisher,
            $this->voiceService,
            $this->presenceService,
            voiceEnabled: false,
            userService: $this->userService,
            channelService: $this->channelService,
        );

        $this->expectException(VoiceDisabledException::class);
        $service->joinAudioChannel($this->createMock(User::class), $this->createMock(Channel::class));
    }

    public function testDisconnectFromChannelKicksAndLeaves(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);
        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(10);

        $this->voiceService->expects(self::once())
            ->method('removeParticipant')
            ->with('channel-10', 'user-7');

        $this->service->disconnectFromChannel($user, $channel);
    }

    public function testDisconnectFromCommunityOnlyThatCommunity(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);
        $target = $this->createMock(Community::class);
        $target->method('getId')->willReturn(1);
        $other = $this->createMock(Community::class);
        $other->method('getId')->willReturn(2);

        $inTarget = $this->createMock(Channel::class);
        $inTarget->method('getId')->willReturn(5);
        $inTarget->method('getCommunity')->willReturn($target);
        $inOther = $this->createMock(Channel::class);
        $inOther->method('getId')->willReturn(6);
        $inOther->method('getCommunity')->willReturn($other);

        $this->participantStore->save($user, $inTarget, 'user-7');
        $this->participantStore->save($user, $inOther, 'user-7');
        $this->channelService->method('find')->willReturnMap([[5, $inTarget], [6, $inOther]]);

        $this->voiceService->expects(self::once())
            ->method('removeParticipant')
            ->with('channel-5', 'user-7');

        $this->service->disconnectFromCommunity($user, $target);
    }

    public function testReconcileAddsLiveOnlyParticipant(): void
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(42);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(9);
        $this->voiceService->method('listParticipantIdentities')->willReturn(['user-9']);
        $this->userService->method('find')->willReturn($user);

        self::assertSame(1, $this->service->reconcileAudioParticipants($channel));

        $participants = $this->participantStore->findByChannel($channel);
        self::assertCount(1, $participants);
        self::assertSame('user-9', $participants[0]->getLivekitIdentity());
    }

    public function testHandleRoomFinishedRemovesAllParticipants(): void
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(42);
        $u1 = $this->createMock(User::class);
        $u1->method('getId')->willReturn(1);
        $u2 = $this->createMock(User::class);
        $u2->method('getId')->willReturn(2);
        $this->participantStore->save($u1, $channel, 'user-1');
        $this->participantStore->save($u2, $channel, 'user-2');

        $this->publisher->expects(self::once())->method('publishAudioChannelParticipants')->with($channel);
        $this->presenceService->expects(self::exactly(2))->method('reevaluate');

        $this->service->handleRoomFinished($channel);

        self::assertSame([], $this->participantStore->findByChannel($channel));
    }

    public function testHandleRoomFinishedNoopWhenEmpty(): void
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(42);

        $this->publisher->expects(self::never())->method('publishAudioChannelParticipants');

        $this->service->handleRoomFinished($channel);
    }

    public function testReconcilePrunesStoredOnlyParticipant(): void
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(42);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(3);
        $this->participantStore->save($user, $channel, 'user-3');
        $this->voiceService->method('listParticipantIdentities')->willReturn([]);

        self::assertSame(1, $this->service->reconcileAudioParticipants($channel));
        self::assertSame([], $this->participantStore->findByChannel($channel));
    }

    public function testReconcileActiveRoomsSkipsNonChannelRooms(): void
    {
        $this->voiceService->method('listActiveRooms')->willReturn(['channel-42', 'lobby']);
        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(42);
        $channel->method('getType')->willReturn(ChannelType::Audio);
        $this->channelService->method('find')->willReturn($channel);
        $this->voiceService->method('listParticipantIdentities')->willReturn([]);

        $this->service->reconcileActiveRooms();
        $this->addToAssertionCount(1);
    }
}
