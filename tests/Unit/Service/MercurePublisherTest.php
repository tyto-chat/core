<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use ApiPlatform\Metadata\IriConverterInterface;
use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\Message;
use App\Entity\MessagePage;
use App\Entity\Notification;
use App\Entity\User;
use App\Service\MediaObject\SignedUrlServiceInterface;
use App\Service\Realtime\MercurePublisher;
use App\Tests\Stub\InMemoryChannelParticipantStore;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AllowMockObjectsWithoutExpectations]
final class MercurePublisherTest extends TestCase
{
    private ?Update $captured = null;

    /**
     * Mercure topics are unversioned opaque channel names — subscriber grants
     * (RealtimeTokenProvider) and the client subscribe to /api/... topics, so
     * a publisher emitting /api/v1/... would be silently dead. IriConverter
     * returns VERSIONED IRIs inside a request (ApiVersionRequestListener pins
     * the router context), hence the versioned stubs here: they prove the
     * publisher strips the version in topic position while payload IRIs stay
     * versioned (they're data, not channel names).
     */
    private function makePublisher(string $iriFromResource): MercurePublisher
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
        );
    }

    public function testPublishCommunityStructureChangedTopicIsUnversioned(): void
    {
        $community = new Community();
        $community->setIdentifier('my-community');

        $publisher = $this->makePublisher('/api/v1/communities/my-community');
        $publisher->publishCommunityStructureChanged($community);

        self::assertSame(['/api/communities/my-community'], $this->captured->getTopics());
        // Every update MUST be private — public updates bypass the subscriber
        // JWT claims and leak to any subscriber naming the topic.
        self::assertTrue($this->captured->isPrivate());
        self::assertStringContainsString('"type":"community.structure"', $this->captured->getData());
        self::assertStringContainsString('"communityIdentifier":"my-community"', $this->captured->getData());
    }

    public function testPublishChannelActivityTopicIsUnversioned(): void
    {
        $community = new Community();
        $community->setIdentifier('my-community');
        $channel = new Channel();
        $channel->setIdentifier('general');
        $channel->setCommunity($community);

        $publisher = $this->makePublisher('/api/v1/communities/my-community');
        $publisher->publishChannelActivity($channel);

        self::assertSame(['/api/communities/my-community/activity'], $this->captured->getTopics());
        self::assertTrue($this->captured->isPrivate());
        self::assertStringContainsString('"type":"channel.activity"', $this->captured->getData());
    }

    public function testPublishCommunityEmojisUpdatedTopicIsUnversioned(): void
    {
        $community = new Community();
        $community->setIdentifier('my-community');

        $publisher = $this->makePublisher('/api/v1/communities/my-community');
        $publisher->publishCommunityEmojisUpdated($community);

        self::assertSame(['/api/communities/my-community/emojis'], $this->captured->getTopics());
        self::assertTrue($this->captured->isPrivate());
    }

    public function testPublishNotificationTopicIsUnversioned(): void
    {
        $notification = new Notification();
        $notification->setRecipient(new User());
        $notification->setCreatedAt(new \DateTime());

        $publisher = $this->makePublisher('/api/v1/users/42');
        $publisher->publishNotification($notification);

        self::assertSame(['/api/users/42/notifications'], $this->captured->getTopics());
        self::assertTrue($this->captured->isPrivate());
        self::assertStringContainsString('"type":"notification"', $this->captured->getData());
    }

    public function testPublishNotificationUpdatedTopicIsUnversioned(): void
    {
        $notification = new Notification();
        $notification->setRecipient(new User());
        $notification->setCreatedAt(new \DateTime());

        $publisher = $this->makePublisher('/api/v1/users/42');
        $publisher->publishNotificationUpdated($notification);

        self::assertSame(['/api/users/42/notifications'], $this->captured->getTopics());
        self::assertTrue($this->captured->isPrivate());
        self::assertStringContainsString('"type":"notification.update"', $this->captured->getData());
    }

    public function testPublishMessageUpdatedPayloadIdStaysVersioned(): void
    {
        $community = new Community();
        $community->setIdentifier('my-community');
        $channel = new Channel();
        $channel->setIdentifier('general');
        $channel->setCommunity($community);
        $page = new MessagePage();
        $page->setChannel($channel);
        $message = new Message();
        $message->setPage($page);

        $publisher = $this->makePublisher('/api/v1/messages/some-uuid');
        $publisher->publishMessageUpdated($message, 'edited body');

        // Topic comes from the entity's own hardcoded container IRI —
        // unversioned by construction, untouched by IriConverter.
        self::assertSame(['/api/communities/my-community/channels/general'], $this->captured->getTopics());
        // Payload @id is DATA, not a channel name — it must stay the real,
        // versioned resource IRI the client uses for cache matching.
        self::assertStringContainsString('"@id":"\/api\/v1\/messages\/some-uuid"', $this->captured->getData());
    }

    public function testPublishUserEvent(): void
    {
        $publisher = $this->makePublisher('/api/v1/unused');
        $publisher->publishUserEvent(42, 'community.removed', ['communityIdentifier' => 'my-community']);

        self::assertSame(['/api/users/42/events'], $this->captured->getTopics());
        self::assertTrue($this->captured->isPrivate());
        self::assertStringContainsString('"event":"community.removed"', $this->captured->getData());
        self::assertStringContainsString('"communityIdentifier":"my-community"', $this->captured->getData());
    }
}
