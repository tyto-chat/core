<?php

declare(strict_types=1);

namespace App\Tests\Integration\Voice;

use App\Entity\Channel;
use App\Entity\User;
use App\Service\User\UserServiceInterface;
use App\Service\Voice\RedisChannelParticipantStore;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Predis\Client;

#[AllowMockObjectsWithoutExpectations]
class RedisChannelParticipantStoreTest extends TestCase
{
    private const CHANNEL_A = 990001;
    private const CHANNEL_B = 990002;
    private const USER_A = 990007;
    private const USER_B = 990008;

    private Client $redis;
    private RedisChannelParticipantStore $store;

    /** @var array<int, User> */
    private array $users = [];

    #[\Override]
    protected function setUp(): void
    {
        $dsn = $_ENV['REDIS_DSN'] ?? $_SERVER['REDIS_DSN'] ?? null;
        self::assertIsString($dsn, 'REDIS_DSN must be set for integration tests.');

        $this->redis = new Client($dsn, ['exceptions' => true]);
        $this->flushKeys();

        $this->users = [
            self::USER_A => $this->buildUser(self::USER_A),
            self::USER_B => $this->buildUser(self::USER_B),
        ];

        $userService = $this->createMock(UserServiceInterface::class);
        $userService->method('findByIds')->willReturnCallback(
            fn (array $ids): array => array_values(array_intersect_key($this->users, array_flip($ids))),
        );

        $this->store = new RedisChannelParticipantStore($this->redis);
        $this->store->setUserService($userService);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->flushKeys();
    }

    private function flushKeys(): void
    {
        $this->redis->del([
            'voice:channel:'.self::CHANNEL_A,
            'voice:channel:'.self::CHANNEL_B,
            'voice:user:'.self::USER_A,
            'voice:user:'.self::USER_B,
        ]);
    }

    private function buildUser(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    private function buildChannel(int $id): Channel
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn($id);

        return $channel;
    }

    public function testSaveRoundTripsIdentityAndJoinedAt(): void
    {
        $channel = $this->buildChannel(self::CHANNEL_A);
        $this->store->save($this->users[self::USER_A], $channel, 'user-'.self::USER_A);

        $participants = $this->store->findByChannel($channel);

        self::assertCount(1, $participants);
        self::assertSame(self::USER_A, $participants[0]->getUserId());
        self::assertSame('user-'.self::USER_A, $participants[0]->getLivekitIdentity());
        self::assertEqualsWithDelta(
            (new \DateTimeImmutable())->getTimestamp(),
            $participants[0]->getJoinedAt()->getTimestamp(),
            5.0,
        );
    }

    public function testResavePreservesJoinedAtAcrossSerialization(): void
    {
        $channel = $this->buildChannel(self::CHANNEL_A);
        $this->store->save($this->users[self::USER_A], $channel, 'user-a');
        $joinedAt = $this->store->findOneByUserAndChannel($this->users[self::USER_A], $channel)?->getJoinedAt();
        self::assertNotNull($joinedAt);

        $this->store->save($this->users[self::USER_A], $channel, 'user-a-reconnected');

        $participant = $this->store->findOneByUserAndChannel($this->users[self::USER_A], $channel);
        self::assertNotNull($participant);
        self::assertSame($joinedAt->getTimestamp(), $participant->getJoinedAt()->getTimestamp());
        self::assertSame('user-a-reconnected', $participant->getLivekitIdentity());
    }

    public function testFindOneReturnsNullWhenAbsent(): void
    {
        self::assertNull($this->store->findOneByUserAndChannel(
            $this->users[self::USER_A],
            $this->buildChannel(self::CHANNEL_A),
        ));
    }

    public function testRemoveClearsChannelFieldAndUserIndex(): void
    {
        $channel = $this->buildChannel(self::CHANNEL_A);
        $this->store->save($this->users[self::USER_A], $channel, 'user-a');
        $this->store->save($this->users[self::USER_B], $channel, 'user-b');

        $this->store->remove($this->users[self::USER_A], $channel);

        $participants = $this->store->findByChannel($channel);
        self::assertCount(1, $participants);
        self::assertSame(self::USER_B, $participants[0]->getUserId());
        self::assertSame([], $this->store->findChannelIdsByUser($this->users[self::USER_A]));
        self::assertNull($this->redis->get('voice:user:'.self::USER_A));
    }

    public function testRemoveKeepsOtherChannelInUserIndex(): void
    {
        $channelA = $this->buildChannel(self::CHANNEL_A);
        $channelB = $this->buildChannel(self::CHANNEL_B);
        $this->store->save($this->users[self::USER_A], $channelA, 'user-a');
        $this->store->save($this->users[self::USER_A], $channelB, 'user-a');

        $this->store->remove($this->users[self::USER_A], $channelA);

        self::assertSame([self::CHANNEL_B], $this->store->findChannelIdsByUser($this->users[self::USER_A]));
    }

    public function testClearDropsChannelAndEveryUserIndexEntry(): void
    {
        $channel = $this->buildChannel(self::CHANNEL_A);
        $this->store->save($this->users[self::USER_A], $channel, 'user-a');
        $this->store->save($this->users[self::USER_B], $channel, 'user-b');

        $this->store->clear($channel);

        self::assertSame([], $this->store->findByChannel($channel));
        self::assertSame([], $this->store->findChannelIdsByUser($this->users[self::USER_A]));
        self::assertSame([], $this->store->findChannelIdsByUser($this->users[self::USER_B]));
    }

    public function testFindChannelIdsByUserSpansChannels(): void
    {
        $this->store->save($this->users[self::USER_A], $this->buildChannel(self::CHANNEL_A), 'user-a');
        $this->store->save($this->users[self::USER_A], $this->buildChannel(self::CHANNEL_B), 'user-a');

        self::assertSame(
            [self::CHANNEL_A, self::CHANNEL_B],
            $this->store->findChannelIdsByUser($this->users[self::USER_A]),
        );
    }

    public function testFindActiveUserIdsFiltersAndPreservesOrder(): void
    {
        $this->store->save($this->users[self::USER_B], $this->buildChannel(self::CHANNEL_A), 'user-b');

        self::assertSame(
            [self::USER_B],
            $this->store->findActiveUserIds([self::USER_A, self::USER_B]),
        );
        self::assertSame([], $this->store->findActiveUserIds([]));
    }

    public function testSaveSetsTtlOnBothKeyFamilies(): void
    {
        $channel = $this->buildChannel(self::CHANNEL_A);
        $this->store->save($this->users[self::USER_A], $channel, 'user-a');

        $channelTtl = (int) $this->redis->ttl('voice:channel:'.self::CHANNEL_A);
        $userTtl = (int) $this->redis->ttl('voice:user:'.self::USER_A);

        self::assertGreaterThan(0, $channelTtl);
        self::assertLessThanOrEqual(3600, $channelTtl);
        self::assertGreaterThan(0, $userTtl);
        self::assertLessThanOrEqual(3600, $userTtl);
    }

    public function testRefreshTtlExtendsShortenedKeys(): void
    {
        $channel = $this->buildChannel(self::CHANNEL_A);
        $this->store->save($this->users[self::USER_A], $channel, 'user-a');
        $this->redis->expire('voice:channel:'.self::CHANNEL_A, 10);
        $this->redis->expire('voice:user:'.self::USER_A, 10);

        $this->store->refreshTtl($channel);

        self::assertGreaterThan(10, (int) $this->redis->ttl('voice:channel:'.self::CHANNEL_A));
        self::assertGreaterThan(10, (int) $this->redis->ttl('voice:user:'.self::USER_A));
    }

    public function testRefreshTtlIsNoopForEmptyChannel(): void
    {
        $this->store->refreshTtl($this->buildChannel(self::CHANNEL_A));

        self::assertSame(-2, (int) $this->redis->ttl('voice:channel:'.self::CHANNEL_A));
    }

    public function testMalformedEntryIsSkippedRatherThanFatal(): void
    {
        $channel = $this->buildChannel(self::CHANNEL_A);
        $this->store->save($this->users[self::USER_A], $channel, 'user-a');
        $this->redis->hset('voice:channel:'.self::CHANNEL_A, (string) self::USER_B, 'not-json');

        $participants = $this->store->findByChannel($channel);

        self::assertCount(1, $participants);
        self::assertSame(self::USER_A, $participants[0]->getUserId());
    }

    public function testMalformedUserIndexReadsAsNoChannels(): void
    {
        $this->redis->set('voice:user:'.self::USER_A, 'not-json');

        self::assertSame([], $this->store->findChannelIdsByUser($this->users[self::USER_A]));
        self::assertSame([], $this->store->findActiveUserIds([self::USER_A]));
    }
}
