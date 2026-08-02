<?php

declare(strict_types=1);

namespace App\Tests\Integration\Presence;

use App\Entity\Community;
use App\Entity\User;
use App\Enum\Presence\ManualStatus;
use App\Enum\Presence\PresenceState;
use App\Security\SecurityContext;
use App\Service\Channel\ChannelAudioServiceInterface;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Presence\RedisPresenceService;
use App\Service\Realtime\RealtimePublisherInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Symfony\Bundle\SecurityBundle\Security;

#[AllowMockObjectsWithoutExpectations]
class RedisPresenceServiceTest extends TestCase
{
    private const USER_A = 991001;
    private const USER_B = 991002;

    private Client $redis;
    private RedisPresenceService $service;

    /** @var list<array{int, PresenceState}> */
    private array $published = [];

    /** @var list<int> */
    private array $voiceUserIds = [];

    /** @var list<int> */
    private array $memberUserIds = [];

    #[\Override]
    protected function setUp(): void
    {
        $dsn = $_ENV['REDIS_DSN'] ?? $_SERVER['REDIS_DSN'] ?? null;
        self::assertIsString($dsn, 'REDIS_DSN must be set for integration tests.');

        $this->redis = new Client($dsn, ['exceptions' => true]);
        $this->flushKeys();
        $this->published = [];
        $this->voiceUserIds = [];
        $this->memberUserIds = [];

        $membership = $this->createMock(CommunityMembershipServiceInterface::class);
        $membership->method('findMemberUserIds')->willReturnCallback(fn (): array => $this->memberUserIds);

        $symfonySecurity = $this->createMock(Security::class);
        $symfonySecurity->method('isGranted')->willReturn(true);
        $security = new SecurityContext($symfonySecurity, $membership);

        $realtime = $this->createMock(RealtimePublisherInterface::class);
        $realtime->method('publishPresenceChanged')->willReturnCallback(
            function (int $userId, PresenceState $state): void {
                $this->published[] = [$userId, $state];
            },
        );

        $audio = $this->createMock(ChannelAudioServiceInterface::class);
        $audio->method('filterActiveVoiceUserIds')->willReturnCallback(
            fn (array $ids): array => array_values(array_intersect($ids, $this->voiceUserIds)),
        );

        $this->service = new RedisPresenceService($security, $membership, $this->redis, $realtime);
        $this->service->setChannelAudioService($audio);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->flushKeys();
    }

    private function flushKeys(): void
    {
        foreach ([self::USER_A, self::USER_B] as $id) {
            $this->redis->del(['presence:user:'.$id.':live', 'presence:user:'.$id.':manual']);
        }
    }

    private function buildUser(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    private function stateOf(int $userId): PresenceState
    {
        return $this->service->getBatch([$userId])[$userId]->state;
    }

    public function testTouchSetsLiveKeyAndPublishesOfflineToOnline(): void
    {
        $this->service->touch($this->buildUser(self::USER_A));

        $ttl = (int) $this->redis->ttl('presence:user:'.self::USER_A.':live');
        self::assertGreaterThan(150, $ttl);
        self::assertLessThanOrEqual(180, $ttl);
        self::assertSame([[self::USER_A, PresenceState::Online]], $this->published);
        self::assertSame(PresenceState::Online, $this->stateOf(self::USER_A));
    }

    public function testSecondTouchWithinThresholdIsThrottled(): void
    {
        $user = $this->buildUser(self::USER_A);
        $this->service->touch($user);
        $this->redis->expire('presence:user:'.self::USER_A.':live', 160);

        $this->service->touch($user);

        self::assertSame(160, (int) $this->redis->ttl('presence:user:'.self::USER_A.':live'));
        self::assertCount(1, $this->published);
    }

    public function testTouchRefreshesWhenTtlDropsBelowThresholdWithoutRepublishing(): void
    {
        $user = $this->buildUser(self::USER_A);
        $this->service->touch($user);
        $this->redis->expire('presence:user:'.self::USER_A.':live', 100);

        $this->service->touch($user);

        self::assertGreaterThan(150, (int) $this->redis->ttl('presence:user:'.self::USER_A.':live'));
        self::assertCount(1, $this->published, 'Still online — no transition, no second publish.');
    }

    public function testManualAwayWinsOverLive(): void
    {
        $user = $this->buildUser(self::USER_A);
        $this->service->touch($user);

        $this->service->setManualStatus($user, ManualStatus::Away);

        self::assertSame(PresenceState::Away, $this->stateOf(self::USER_A));
        self::assertSame([self::USER_A, PresenceState::Away], end($this->published));
    }

    public function testInvisibleBeatsLiveAndVoice(): void
    {
        $user = $this->buildUser(self::USER_A);
        $this->voiceUserIds = [self::USER_A];
        $this->service->touch($user);

        $this->service->setManualStatus($user, ManualStatus::Invisible);

        self::assertSame(PresenceState::Offline, $this->stateOf(self::USER_A));
        self::assertSame([self::USER_A, PresenceState::Offline], end($this->published));
    }

    public function testClearingManualStatusFallsBackToLive(): void
    {
        $user = $this->buildUser(self::USER_A);
        $this->service->touch($user);
        $this->service->setManualStatus($user, ManualStatus::Dnd);

        $this->service->setManualStatus($user, null);

        self::assertNull($this->redis->get('presence:user:'.self::USER_A.':manual'));
        self::assertSame(PresenceState::Online, $this->stateOf(self::USER_A));
        self::assertSame([self::USER_A, PresenceState::Online], end($this->published));
    }

    public function testVoiceAloneKeepsUserOnline(): void
    {
        $this->voiceUserIds = [self::USER_A];

        self::assertSame(PresenceState::Online, $this->stateOf(self::USER_A));
    }

    public function testOfflineDeletesLiveKeyAndPublishesTransition(): void
    {
        $user = $this->buildUser(self::USER_A);
        $this->service->touch($user);

        $this->service->offline($user);

        self::assertNull($this->redis->get('presence:user:'.self::USER_A.':live'));
        self::assertSame([self::USER_A, PresenceState::Offline], end($this->published));
    }

    public function testOfflineDoesNotPublishWhenManualStatusMasksIt(): void
    {
        $user = $this->buildUser(self::USER_A);
        $this->service->touch($user);
        $this->service->setManualStatus($user, ManualStatus::Away);
        $publishesBefore = \count($this->published);

        $this->service->offline($user);

        self::assertSame(PresenceState::Away, $this->stateOf(self::USER_A));
        self::assertCount($publishesBefore, $this->published, 'Away before and after — no transition.');
    }

    public function testMalformedManualValueReadsAsNoManualStatus(): void
    {
        $this->redis->set('presence:user:'.self::USER_A.':manual', 'not-a-status');
        $this->service->touch($this->buildUser(self::USER_A));

        self::assertSame(PresenceState::Online, $this->stateOf(self::USER_A));
    }

    public function testGetBatchMixesStatesAndDefaultsToOffline(): void
    {
        $this->service->touch($this->buildUser(self::USER_A));
        $this->service->setManualStatus($this->buildUser(self::USER_B), ManualStatus::Dnd);

        $batch = $this->service->getBatch([self::USER_A, self::USER_B, 991003]);

        self::assertSame(PresenceState::Online, $batch[self::USER_A]->state);
        self::assertSame(PresenceState::Dnd, $batch[self::USER_B]->state);
        self::assertSame(PresenceState::Offline, $batch[991003]->state);
        self::assertSame([], $this->service->getBatch([]));
    }

    public function testGetForUnsavedUserIsOffline(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(null);

        self::assertSame(PresenceState::Offline, $this->service->get($user)->state);
    }

    public function testCommunityOnlineCountSkipsOfflineAndInvisibleMembers(): void
    {
        $this->memberUserIds = [self::USER_A, self::USER_B];
        $this->service->touch($this->buildUser(self::USER_A));
        $this->service->touch($this->buildUser(self::USER_B));
        $this->service->setManualStatus($this->buildUser(self::USER_B), ManualStatus::Invisible);

        $community = $this->createMock(Community::class);

        self::assertSame(1, $this->service->getCommunityOnlineCount($community));

        $online = $this->service->getCommunityOnline($community);
        self::assertCount(1, $online);
        self::assertSame(self::USER_A, $online[0]->userId);
    }

    public function testCommunityOnlineCountIsZeroWithoutMembers(): void
    {
        $this->memberUserIds = [];

        self::assertSame(0, $this->service->getCommunityOnlineCount($this->createMock(Community::class)));
    }
}
