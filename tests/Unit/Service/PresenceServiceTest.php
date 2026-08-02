<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Enum\Presence\ManualStatus;
use App\Enum\Presence\PresenceState;
use App\Service\Channel\ChannelAudioServiceInterface;
use App\Service\Realtime\RealtimePublisherInterface;
use App\Tests\Stub\InMemoryPresenceService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the shared compute() rules used by both RedisPresenceService and the
 * in-memory stub: live signal → Online, manual override beats live, Invisible
 * collapses to Offline, voice override keeps Online without live, transitions
 * publish exactly once.
 */
#[AllowMockObjectsWithoutExpectations]
class PresenceServiceTest extends TestCase
{
    private function buildUser(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    /**
     * @param int[] $voiceUserIds
     */
    private function buildService(
        RealtimePublisherInterface $realtime,
        array $voiceUserIds = [],
    ): InMemoryPresenceService {
        /** @var ChannelAudioServiceInterface&MockObject $audio */
        $audio = $this->createMock(ChannelAudioServiceInterface::class);
        $audio->method('filterActiveVoiceUserIds')
            ->willReturnCallback(static fn (array $ids): array => array_values(array_intersect($ids, $voiceUserIds)));

        $membership = $this->createMock(\App\Service\Community\CommunityMembershipServiceInterface::class);
        $service = new InMemoryPresenceService($realtime, $membership);
        $service->setChannelAudioService($audio);

        return $service;
    }

    public function testTouchPublishesOnFirstTransitionToOnline(): void
    {
        $realtime = $this->createMock(RealtimePublisherInterface::class);
        $realtime->expects(self::once())
            ->method('publishPresenceChanged')
            ->with(42, PresenceState::Online);

        $service = $this->buildService($realtime);
        $service->touch($this->buildUser(42));
    }

    public function testTouchDoesNotRepublishWhenAlreadyOnline(): void
    {
        $realtime = $this->createMock(RealtimePublisherInterface::class);
        $realtime->expects(self::once())->method('publishPresenceChanged');

        $service = $this->buildService($realtime);
        $user = $this->buildUser(42);
        $service->touch($user);
        $service->touch($user);
        $service->touch($user);
    }

    public function testManualAwayBeatsLiveSignal(): void
    {
        $realtime = $this->createMock(RealtimePublisherInterface::class);
        $service = $this->buildService($realtime);
        $user = $this->buildUser(7);

        $service->touch($user);
        self::assertSame(PresenceState::Online, $service->get($user)->state);

        $service->setManualStatus($user, ManualStatus::Away);
        self::assertSame(PresenceState::Away, $service->get($user)->state);
    }

    public function testInvisibleCollapsesToOffline(): void
    {
        $realtime = $this->createMock(RealtimePublisherInterface::class);
        $service = $this->buildService($realtime);
        $user = $this->buildUser(7);

        $service->touch($user);
        $service->setManualStatus($user, ManualStatus::Invisible);

        self::assertSame(PresenceState::Offline, $service->get($user)->state);
    }

    public function testClearingManualRevealsLiveOnline(): void
    {
        $realtime = $this->createMock(RealtimePublisherInterface::class);
        $service = $this->buildService($realtime);
        $user = $this->buildUser(7);

        $service->touch($user);
        $service->setManualStatus($user, ManualStatus::Away);
        $service->setManualStatus($user, null);

        self::assertSame(PresenceState::Online, $service->get($user)->state);
    }

    public function testOfflinePublishesTransitionAndIsIdempotent(): void
    {
        $realtime = $this->createMock(RealtimePublisherInterface::class);
        $realtime->expects(self::exactly(2))
            ->method('publishPresenceChanged');

        $service = $this->buildService($realtime);
        $user = $this->buildUser(9);

        $service->touch($user);
        $service->offline($user);
        $service->offline($user);

        self::assertSame(PresenceState::Offline, $service->get($user)->state);
    }

    public function testGetBatchReturnsOfflineForUnknownUsers(): void
    {
        $realtime = $this->createMock(RealtimePublisherInterface::class);
        $service = $this->buildService($realtime);
        $service->touch($this->buildUser(1));
        $service->setManualStatus($this->buildUser(2), ManualStatus::Dnd);

        $snapshots = $service->getBatch([1, 2, 99]);

        self::assertSame(PresenceState::Online, $snapshots[1]->state);
        self::assertSame(PresenceState::Dnd, $snapshots[2]->state);
        self::assertSame(PresenceState::Offline, $snapshots[99]->state);
    }

    public function testGetBatchHandlesNonContiguousInputKeys(): void
    {
        $realtime = $this->createMock(RealtimePublisherInterface::class);
        $service = $this->buildService($realtime);
        $service->touch($this->buildUser(1));
        $service->setManualStatus($this->buildUser(2), ManualStatus::Dnd);

        $snapshots = $service->getBatch([5 => 1, 9 => 2, 12 => 99]);

        self::assertSame(PresenceState::Online, $snapshots[1]->state);
        self::assertSame(PresenceState::Dnd, $snapshots[2]->state);
        self::assertSame(PresenceState::Offline, $snapshots[99]->state);
    }

    public function testVoiceParticipationKeepsUserOnlineWithoutLiveSignal(): void
    {
        $realtime = $this->createMock(RealtimePublisherInterface::class);
        $service = $this->buildService($realtime, voiceUserIds: [5]);

        self::assertSame(PresenceState::Online, $service->get($this->buildUser(5))->state);
    }

    public function testInvisibleStillBeatsVoiceParticipation(): void
    {
        $realtime = $this->createMock(RealtimePublisherInterface::class);
        $service = $this->buildService($realtime, voiceUserIds: [5]);
        $user = $this->buildUser(5);

        $service->setManualStatus($user, ManualStatus::Invisible);

        self::assertSame(PresenceState::Offline, $service->get($user)->state);
    }

    public function testOfflineDoesNotPublishWhenVoiceKeepsUserOnline(): void
    {
        $realtime = $this->createMock(RealtimePublisherInterface::class);
        $realtime->expects(self::never())->method('publishPresenceChanged');

        $service = $this->buildService($realtime, voiceUserIds: [8]);
        $user = $this->buildUser(8);

        $service->touch($user);
        $service->offline($user);

        self::assertSame(PresenceState::Online, $service->get($user)->state);
    }

    public function testReevaluatePublishesCurrentEffectiveState(): void
    {
        $realtime = $this->createMock(RealtimePublisherInterface::class);
        $realtime->expects(self::once())
            ->method('publishPresenceChanged')
            ->with(3, PresenceState::Online);

        $service = $this->buildService($realtime, voiceUserIds: [3]);
        $service->reevaluate($this->buildUser(3));
    }
}
