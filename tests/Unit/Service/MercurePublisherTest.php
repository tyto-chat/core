<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use ApiPlatform\Metadata\IriConverterInterface;
use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\Conversation;
use App\Entity\ConversationMember;
use App\Entity\Message;
use App\Entity\MessagePage;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\Presence\PresenceState;
use App\Service\MediaObject\SignedUrlServiceInterface;
use App\Service\Realtime\MercurePublisher;
use App\Tests\Stub\InMemoryChannelParticipantStore;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\Exception\RuntimeException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AllowMockObjectsWithoutExpectations]
final class MercurePublisherTest extends TestCase
{
    private ?Update $captured = null;

    private function makePublisher(string $iriFromResource = '/api/v1/unused'): MercurePublisher
    {
        $iri = $this->createMock(IriConverterInterface::class);
        $iri->method('getIriFromResource')->willReturn($iriFromResource);

        $hub = $this->createMock(HubInterface::class);
        $this->captured = null;
        $hub->expects(self::once())->method('publish')
            ->willReturnCallback(function (Update $u): string {
                $this->captured = $u;

                return '';
            });

        return new MercurePublisher(
            $hub,
            $iri,
            new InMemoryChannelParticipantStore(),
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(SignedUrlServiceInterface::class),
            new NullLogger(),
        );
    }

    private function makeSilentPublisher(): MercurePublisher
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('publish');

        return new MercurePublisher(
            $hub,
            $this->createMock(IriConverterInterface::class),
            new InMemoryChannelParticipantStore(),
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(SignedUrlServiceInterface::class),
            new NullLogger(),
        );
    }

    private function withId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }

    private function community(int $id = 5): Community
    {
        $community = new Community();
        $community->setIdentifier('my-community');
        $this->withId($community, $id);

        return $community;
    }

    private function channel(int $id = 12, ?Community $community = null): Channel
    {
        $channel = new Channel();
        $channel->setIdentifier('general');
        $this->withId($channel, $id);
        $channel->setCommunity($community ?? $this->community());

        return $channel;
    }

    private function conversation(int $id = 9): Conversation
    {
        $conversation = new Conversation();
        $this->withId($conversation, $id);

        return $conversation;
    }

    private function rootInChannel(?Channel $channel = null): Message
    {
        $page = new MessagePage();
        $page->setChannel($channel ?? $this->channel());
        $message = new Message();
        $message->setPage($page);

        return $message;
    }

    private function notification(int $recipientId = 42): Notification
    {
        $user = new User();
        $this->withId($user, $recipientId);
        $notification = new Notification();
        $notification->setRecipient($user);
        $notification->setCreatedAt(new \DateTime());

        return $notification;
    }

    public function testPublishCommunityStructureChanged(): void
    {
        $publisher = $this->makePublisher();
        $publisher->publishCommunityStructureChanged($this->community());

        self::assertSame(['k/5'], $this->captured->getTopics());
        self::assertTrue($this->captured->isPrivate());
        self::assertStringContainsString('"type":"community.structure"', $this->captured->getData());
        self::assertStringContainsString('"communityIdentifier":"my-community"', $this->captured->getData());
        self::assertStringNotContainsString('grantsChanged', $this->captured->getData());
    }

    public function testStructureChangeThatAltersGrantsIsFlagged(): void
    {
        $publisher = $this->makePublisher();
        $publisher->publishCommunityStructureChanged($this->community(), grantsChanged: true);

        self::assertStringContainsString('"grantsChanged":true', $this->captured->getData());
    }

    public function testPublishChannelActivity(): void
    {
        $publisher = $this->makePublisher();
        $publisher->publishChannelActivity($this->channel());

        self::assertSame(['k/5/a'], $this->captured->getTopics());
        self::assertTrue($this->captured->isPrivate());
        self::assertStringContainsString('"type":"channel.activity"', $this->captured->getData());
    }

    public function testPublishChannelActivityWithoutCommunityPublishesNothing(): void
    {
        $channel = new Channel();
        $this->withId($channel, 12);

        $this->makeSilentPublisher()->publishChannelActivity($channel);
    }

    public function testPublishChannelTyping(): void
    {
        $publisher = $this->makePublisher();
        $publisher->publishChannelTyping($this->channel(), 7, 'Ann');

        self::assertSame(['c/12'], $this->captured->getTopics());
        self::assertStringContainsString('"type":"typing"', $this->captured->getData());
    }

    public function testPublishChannelTypingWithoutIdPublishesNothing(): void
    {
        $this->makeSilentPublisher()->publishChannelTyping(new Channel(), 7, 'Ann');
    }

    public function testPublishConversationTyping(): void
    {
        $publisher = $this->makePublisher();
        $publisher->publishConversationTyping($this->conversation(), 7, 'Ann');

        self::assertSame(['m/9'], $this->captured->getTopics());
    }

    public function testPublishConversationTypingWithoutIdPublishesNothing(): void
    {
        $this->makeSilentPublisher()->publishConversationTyping(new Conversation(), 7, 'Ann');
    }

    public function testPublishMessageUpdatedPayloadIdStaysVersioned(): void
    {
        $publisher = $this->makePublisher('/api/v1/messages/some-uuid');
        $publisher->publishMessageUpdated($this->rootInChannel(), 'edited body');

        self::assertSame(['c/12'], $this->captured->getTopics());
        self::assertStringContainsString('"@id":"\/api\/v1\/messages\/some-uuid"', $this->captured->getData());
    }

    public function testPublishMessageUpdatedForReplyUsesThreadTopic(): void
    {
        $root = $this->rootInChannel();
        $reply = new Message();
        $reply->setPage($root->getPage());
        $reply->setParent($root);

        $publisher = $this->makePublisher();
        $publisher->publishMessageUpdated($reply, 'x');

        self::assertSame(['c/12/t'], $this->captured->getTopics());
    }

    public function testPublishMessageUpdatedWithoutContainerPublishesNothing(): void
    {
        $this->makeSilentPublisher()->publishMessageUpdated(new Message(), 'x');
    }

    public function testPublishMessageDeleted(): void
    {
        $publisher = $this->makePublisher();
        $publisher->publishMessageDeleted($this->rootInChannel());

        self::assertSame(['c/12'], $this->captured->getTopics());
        self::assertStringContainsString('"isDeleted":true', $this->captured->getData());
    }

    public function testPublishMessageReactions(): void
    {
        $publisher = $this->makePublisher();
        $publisher->publishMessageReactions($this->rootInChannel());

        self::assertSame(['c/12'], $this->captured->getTopics());
        self::assertStringContainsString('"reactions"', $this->captured->getData());
    }

    public function testPublishMessageAttachmentsUpdated(): void
    {
        $publisher = $this->makePublisher();
        $publisher->publishMessageAttachmentsUpdated($this->rootInChannel());

        self::assertSame(['c/12'], $this->captured->getTopics());
        self::assertStringContainsString('"attachments"', $this->captured->getData());
    }

    public function testPublishMessagePinnedUsesContainerTopic(): void
    {
        $publisher = $this->makePublisher();
        $publisher->publishMessagePinned($this->rootInChannel());

        self::assertSame(['c/12'], $this->captured->getTopics());
        self::assertStringContainsString('"pinned"', $this->captured->getData());
    }

    public function testPublishMessageThreadMetaInConversationUsesContainerTopic(): void
    {
        $page = new MessagePage();
        $page->setConversation($this->conversation());
        $root = new Message();
        $root->setPage($page);

        $publisher = $this->makePublisher();
        $publisher->publishMessageThreadMeta($root);

        self::assertSame(['m/9'], $this->captured->getTopics());
        self::assertStringContainsString('"replyCount"', $this->captured->getData());
    }

    public function testPublishNotification(): void
    {
        $publisher = $this->makePublisher('/api/v1/users/42');
        $publisher->publishNotification($this->notification());

        self::assertSame(['u/42/n'], $this->captured->getTopics());
        self::assertTrue($this->captured->isPrivate());
        self::assertStringContainsString('"type":"notification"', $this->captured->getData());
    }

    public function testPublishNotificationUpdated(): void
    {
        $publisher = $this->makePublisher('/api/v1/users/42');
        $publisher->publishNotificationUpdated($this->notification());

        self::assertSame(['u/42/n'], $this->captured->getTopics());
        self::assertStringContainsString('"type":"notification.update"', $this->captured->getData());
    }

    public function testPublishNotificationWithoutRecipientIdPublishesNothing(): void
    {
        $notification = new Notification();
        $notification->setRecipient(new User());
        $notification->setCreatedAt(new \DateTime());

        $this->makeSilentPublisher()->publishNotification($notification);
    }

    public function testPublishAudioChannelParticipants(): void
    {
        $publisher = $this->makePublisher();
        $publisher->publishAudioChannelParticipants($this->channel());

        self::assertSame(['c/12/p'], $this->captured->getTopics());
        self::assertStringContainsString('"type":"channel.participants"', $this->captured->getData());
    }

    public function testPublishAudioChannelParticipantsWithoutIdPublishesNothing(): void
    {
        $this->makeSilentPublisher()->publishAudioChannelParticipants(new Channel());
    }

    public function testPublishCommunityEmojisUpdated(): void
    {
        $publisher = $this->makePublisher();
        $publisher->publishCommunityEmojisUpdated($this->community());

        self::assertSame(['k/5/e'], $this->captured->getTopics());
        self::assertTrue($this->captured->isPrivate());
    }

    public function testPublishUserEvent(): void
    {
        $publisher = $this->makePublisher();
        $publisher->publishUserEvent(42, 'community.removed', ['communityIdentifier' => 'my-community']);

        self::assertSame(['u/42/e'], $this->captured->getTopics());
        self::assertTrue($this->captured->isPrivate());
        self::assertStringContainsString('"event":"community.removed"', $this->captured->getData());
        self::assertStringContainsString('"communityIdentifier":"my-community"', $this->captured->getData());
    }

    public function testPublishPresenceChanged(): void
    {
        $publisher = $this->makePublisher();
        $publisher->publishPresenceChanged(42, PresenceState::Online);

        self::assertSame(['u/42/p'], $this->captured->getTopics());
        self::assertStringContainsString('"state":"online"', $this->captured->getData());
    }

    public function testPublishPresenceChangedSendsOneUpdatePerTopic(): void
    {
        $captured = [];
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::exactly(3))->method('publish')
            ->willReturnCallback(static function (Update $u) use (&$captured): string {
                $captured[] = $u;

                return '';
            });
        $publisher = new MercurePublisher(
            $hub,
            $this->createMock(IriConverterInterface::class),
            new InMemoryChannelParticipantStore(),
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(SignedUrlServiceInterface::class),
            new NullLogger(),
        );

        $publisher->publishPresenceChanged(42, PresenceState::Away, [5, 9]);

        self::assertSame([['u/42/p'], ['k/5/p'], ['k/9/p']], array_map(static fn (Update $u): array => $u->getTopics(), $captured));
        foreach ($captured as $update) {
            self::assertTrue($update->isPrivate());
            self::assertStringContainsString('"userId":42', $update->getData());
        }
    }

    public function testPublishConversationActivityTargetsEachMember(): void
    {
        $topics = [];
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $u) use (&$topics): string {
            $topics[] = $u->getTopics()[0];

            return '';
        });
        $publisher = new MercurePublisher(
            $hub,
            $this->createMock(IriConverterInterface::class),
            new InMemoryChannelParticipantStore(),
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(SignedUrlServiceInterface::class),
            new NullLogger(),
        );

        $conversation = $this->conversation();
        foreach ([3, 4] as $userId) {
            $user = new User();
            $this->withId($user, $userId);
            $member = new ConversationMember();
            $member->setUser($user);
            $conversation->addMember($member);
        }

        $publisher->publishConversationActivity($conversation);

        self::assertSame(['u/3/d', 'u/4/d'], $topics);
    }

    public function testHubFailureIsLoggedNotThrown(): void
    {
        $iri = $this->createMock(IriConverterInterface::class);
        $iri->method('getIriFromResource')->willReturn('/api/v1/users/42');

        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willThrowException(new RuntimeException('Failed to connect to localhost port 8890'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with(self::stringContains('Mercure publish failed'), self::callback(
                fn (array $context): bool => 'u/42/n' === $context['topic']
            ));

        $publisher = new MercurePublisher(
            $hub,
            $iri,
            new InMemoryChannelParticipantStore(),
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(SignedUrlServiceInterface::class),
            $logger,
        );

        $publisher->publishNotification($this->notification());
    }
}
