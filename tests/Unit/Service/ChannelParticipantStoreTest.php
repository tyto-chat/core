<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\ChannelParticipant;
use App\Entity\User;
use App\Tests\Stub\InMemoryChannelParticipantStore;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ChannelParticipantStoreTest extends TestCase
{
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

    public function testSaveThenFindByChannelReturnsParticipant(): void
    {
        $store = new InMemoryChannelParticipantStore();
        $user = $this->buildUser(7);
        $channel = $this->buildChannel(42);

        $store->save($user, $channel, 'user-7');

        $participants = $store->findByChannel($channel);
        self::assertCount(1, $participants);
        self::assertSame(7, $participants[0]->getUserId());
        self::assertSame('user-7', $participants[0]->getLivekitIdentity());
    }

    public function testResaveKeepsJoinedAtAndOverwritesIdentity(): void
    {
        $store = new InMemoryChannelParticipantStore();
        $user = $this->buildUser(7);
        $channel = $this->buildChannel(42);

        $store->save($user, $channel, 'user-7');
        $joinedAt = $store->findOneByUserAndChannel($user, $channel)?->getJoinedAt();

        $store->save($user, $channel, 'user-7-reconnected');

        $participant = $store->findOneByUserAndChannel($user, $channel);
        self::assertNotNull($participant);
        self::assertEquals($joinedAt, $participant->getJoinedAt());
        self::assertSame('user-7-reconnected', $participant->getLivekitIdentity());
    }

    public function testFindOneReturnsNullWhenAbsent(): void
    {
        $store = new InMemoryChannelParticipantStore();

        self::assertNull($store->findOneByUserAndChannel($this->buildUser(7), $this->buildChannel(42)));
    }

    public function testRemoveDropsOnlyThatUser(): void
    {
        $store = new InMemoryChannelParticipantStore();
        $channel = $this->buildChannel(42);
        $store->save($this->buildUser(7), $channel, 'user-7');
        $store->save($this->buildUser(8), $channel, 'user-8');

        $store->remove($this->buildUser(7), $channel);

        $participants = $store->findByChannel($channel);
        self::assertCount(1, $participants);
        self::assertSame(8, $participants[0]->getUserId());
    }

    public function testClearEmptiesChannelAndUserIndex(): void
    {
        $store = new InMemoryChannelParticipantStore();
        $channel = $this->buildChannel(42);
        $user = $this->buildUser(7);
        $store->save($user, $channel, 'user-7');

        $store->clear($channel);

        self::assertSame([], $store->findByChannel($channel));
        self::assertSame([], $store->findChannelIdsByUser($user));
    }

    public function testFindChannelIdsByUserSpansChannels(): void
    {
        $store = new InMemoryChannelParticipantStore();
        $user = $this->buildUser(7);
        $store->save($user, $this->buildChannel(42), 'user-7');
        $store->save($user, $this->buildChannel(43), 'user-7');

        self::assertSame([42, 43], $store->findChannelIdsByUser($user));
    }

    public function testFindActiveUserIdsFiltersToParticipants(): void
    {
        $store = new InMemoryChannelParticipantStore();
        $store->save($this->buildUser(7), $this->buildChannel(42), 'user-7');

        self::assertSame([7], $store->findActiveUserIds([7, 8, 9]));
        self::assertSame([], $store->findActiveUserIds([]));
    }

    public function testFindByChannelOrdersByJoinedAt(): void
    {
        $store = new InMemoryChannelParticipantStore();
        $channel = $this->buildChannel(42);
        $store->save($this->buildUser(7), $channel, 'user-7');
        $store->save($this->buildUser(8), $channel, 'user-8');

        $ids = array_map(
            static fn (ChannelParticipant $p): ?int => $p->getUserId(),
            $store->findByChannel($channel),
        );
        self::assertSame([7, 8], $ids);
    }
}
