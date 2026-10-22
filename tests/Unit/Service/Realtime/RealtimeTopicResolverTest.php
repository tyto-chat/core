<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Realtime;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\Conversation;
use App\Entity\User;
use App\Enum\Channel\ChannelType;
use App\Exception\AccessDeniedException;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\Realtime\RealtimeTopicResolver;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class RealtimeTopicResolverTest extends TestCase
{
    private ChannelServiceInterface&Stub $channels;
    private CommunityServiceInterface&Stub $communities;
    private ConversationServiceInterface&Stub $conversations;
    private RealtimeTopicResolver $resolver;

    protected function setUp(): void
    {
        $this->channels = self::createStub(ChannelServiceInterface::class);
        $this->communities = self::createStub(CommunityServiceInterface::class);
        $this->conversations = self::createStub(ConversationServiceInterface::class);
        $this->resolver = new RealtimeTopicResolver($this->channels, $this->communities, $this->conversations);
    }

    public function testUserWithoutScopeGetsOnlyOwnTopicsAndPresence(): void
    {
        $communities = $this->createMock(CommunityServiceInterface::class);
        $communities->expects(self::never())->method('getByIdentifier');
        $conversations = $this->createMock(ConversationServiceInterface::class);
        $conversations->expects(self::never())->method('getByIdentifier');
        $resolver = new RealtimeTopicResolver($this->channels, $communities, $conversations);

        self::assertSame(
            ['u/42/n', 'u/42/e', 'u/42/d', 'u/{userId}/p'],
            $resolver->forUser($this->user(42), null, ''),
        );
    }

    public function testCommunityScopeAddsCommunityTopicsAndViewableChannelsOnly(): void
    {
        $community = $this->community(5, 'acme');
        $this->communities->method('getByIdentifier')->willReturnCallback(fn (string $id) => 'acme' === $id ? $community : throw new AccessDeniedException());
        $this->channels->method('getViewableInCommunity')->willReturnCallback(fn (Community $c) => $c === $community ? [
            $this->channel(12, ChannelType::Text),
            $this->channel(13, ChannelType::Audio),
        ] : []);

        self::assertSame(
            ['u/42/n', 'u/42/e', 'u/42/d', 'u/{userId}/p', 'k/5', 'k/5/a', 'k/5/e', 'k/5/p', 'c/12', 'c/12/t', 'c/13', 'c/13/t', 'c/13/p'],
            $this->resolver->forUser($this->user(42), 'acme', null),
        );
    }

    public function testDeniedCommunityAndConversationAreSilentlyDropped(): void
    {
        $this->communities->method('getByIdentifier')->willThrowException(new AccessDeniedException());
        $this->conversations->method('getByIdentifier')->willThrowException(new AccessDeniedException());
        $channels = $this->createMock(ChannelServiceInterface::class);
        $channels->expects(self::never())->method('getViewableInCommunity');
        $resolver = new RealtimeTopicResolver($channels, $this->communities, $this->conversations);

        self::assertSame(
            ['u/42/n', 'u/42/e', 'u/42/d', 'u/{userId}/p'],
            $resolver->forUser($this->user(42), 'secret', 'someone-elses-dm'),
        );
    }

    public function testUnexpectedErrorsAreNotSwallowed(): void
    {
        $this->communities->method('getByIdentifier')->willThrowException(new \RuntimeException('db down'));

        $this->expectException(\RuntimeException::class);
        $this->resolver->forUser($this->user(42), 'acme', null);
    }

    public function testConversationScopeAddsConversationAndThreadTopics(): void
    {
        $conversation = $this->conversation(7);
        $this->conversations->method('getByIdentifier')->willReturnCallback(fn (string $id) => 'dm-1' === $id ? $conversation : throw new AccessDeniedException());

        self::assertSame(
            ['u/42/n', 'u/42/e', 'u/42/d', 'u/{userId}/p', 'm/7', 'm/7/t'],
            $this->resolver->forUser($this->user(42), null, 'dm-1'),
        );
    }

    public function testAnonymousGetsPublicTextChannelsAndCommunityWithoutActivity(): void
    {
        $community = $this->community(5, 'acme');
        $this->communities->method('getByIdentifier')->willReturn($community);
        $channel = $this->channel(12, ChannelType::Text);
        $this->channels->method('getViewableTextChannels')->willReturnCallback(fn (Community $c) => $c === $community ? [$channel] : []);

        self::assertSame(['c/12', 'c/12/t', 'k/5', 'k/5/e'], $this->resolver->forAnonymous('acme'));
    }

    public function testAnonymousWithNoVisibleChannelsGetsNothing(): void
    {
        $this->communities->method('getByIdentifier')->willReturn($this->community(5, 'acme'));
        $this->channels->method('getViewableTextChannels')->willReturn([]);

        self::assertSame([], $this->resolver->forAnonymous('acme'));
    }

    public function testAnonymousWithoutOrDeniedCommunityGetsNothing(): void
    {
        $this->communities->method('getByIdentifier')->willThrowException(new AccessDeniedException());

        self::assertSame([], $this->resolver->forAnonymous(null));
        self::assertSame([], $this->resolver->forAnonymous('private-one'));
    }

    private function user(int $id): User
    {
        return $this->withId(new User(), $id);
    }

    private function community(int $id, string $identifier): Community
    {
        return $this->withId((new Community())->setIdentifier($identifier), $id);
    }

    private function channel(int $id, ChannelType $type): Channel
    {
        return $this->withId((new Channel())->setType($type), $id);
    }

    private function conversation(int $id): Conversation
    {
        return $this->withId(new Conversation(), $id);
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function withId(object $entity, int $id): object
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);

        return $entity;
    }
}
