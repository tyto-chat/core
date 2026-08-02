<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\HttpCache;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\MessagePage;
use App\Repository\MessagePageRepository;
use App\Service\HttpCache\CachePurgerInterface;
use App\Service\HttpCache\CachePurgingMessagePublisher;
use App\Service\Realtime\MessageRealtimePublisherInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class CachePurgingMessagePublisherTest extends TestCase
{
    private MessageRealtimePublisherInterface&MockObject $inner;
    private CachePurgerInterface&MockObject $purger;
    private MessagePageRepository&MockObject $pages;
    private CachePurgingMessagePublisher $decorator;
    private Message $channelMessage;

    #[\Override]
    protected function setUp(): void
    {
        $this->inner = $this->createMock(MessageRealtimePublisherInterface::class);
        $this->purger = $this->createMock(CachePurgerInterface::class);
        $this->pages = $this->createMock(MessagePageRepository::class);
        $this->decorator = new CachePurgingMessagePublisher($this->inner, $this->purger, $this->pages);

        $this->channelMessage = $this->makeChannelMessage(7, 'chan', 'comm');
    }

    private function makeChannelMessage(int $pageNumber, string $channelIdentifier, string $communityIdentifier): Message
    {
        $community = new Community();
        $community->setIdentifier($communityIdentifier);

        $channel = new Channel();
        $channel->setIdentifier($channelIdentifier);
        $channel->setCommunity($community);

        $page = new MessagePage();
        $page->setPageNumber($pageNumber);
        $page->setChannel($channel);

        $message = new Message();
        $message->setPage($page);

        return $message;
    }

    private function makeConversationMessage(): Message
    {
        $page = new MessagePage();
        $page->setPageNumber(3);
        $page->setConversation(new Conversation());

        $message = new Message();
        $message->setPage($page);

        return $message;
    }

    public function testMessageUpdatePurgesItsPage(): void
    {
        $this->inner->expects(self::once())->method('publishMessageUpdated')->with($this->channelMessage, '<p>x</p>');
        $this->purger->expects(self::once())->method('purgeMessage')->with($this->channelMessage->getId());
        $this->purger->expects(self::once())->method('purgeChannelPage')->with('comm', 'chan', 7);
        $this->purger->expects(self::once())->method('purgeChannelExtras')->with('comm', 'chan');

        $this->decorator->publishMessageUpdated($this->channelMessage, '<p>x</p>');
    }

    public function testConversationMessagePublishesWithoutChannelPurgingButStillPurgesItem(): void
    {
        // DM messages are never cached at the container (page) level, but the
        // bare-item URL is Vary'd per-user and must still invalidate on edit —
        // this is the security-critical half of the chokepoint (see brief).
        $conversationMessage = $this->makeConversationMessage();

        $this->inner->expects(self::once())->method('publishMessageUpdated')->with($conversationMessage, '<p>x</p>');
        $this->purger->expects(self::once())->method('purgeMessage')->with($conversationMessage->getId());
        $this->purger->expects(self::never())->method('purgeChannelPage');
        $this->purger->expects(self::never())->method('purgeChannelExtras');

        $this->decorator->publishMessageUpdated($conversationMessage, '<p>x</p>');
    }

    public function testDeletePurges(): void
    {
        $this->inner->expects(self::once())->method('publishMessageDeleted')->with($this->channelMessage);
        $this->purger->expects(self::once())->method('purgeMessage')->with($this->channelMessage->getId());
        $this->purger->expects(self::once())->method('purgeChannelPage')->with('comm', 'chan', 7);
        $this->purger->expects(self::once())->method('purgeChannelExtras')->with('comm', 'chan');

        $this->decorator->publishMessageDeleted($this->channelMessage);
    }

    public function testReactionsPurge(): void
    {
        $this->inner->expects(self::once())->method('publishMessageReactions')->with($this->channelMessage);
        $this->purger->expects(self::once())->method('purgeMessage')->with($this->channelMessage->getId());
        $this->purger->expects(self::once())->method('purgeChannelPage')->with('comm', 'chan', 7);
        $this->purger->expects(self::once())->method('purgeChannelExtras')->with('comm', 'chan');

        $this->decorator->publishMessageReactions($this->channelMessage);
    }

    public function testAttachmentsUpdatedPurges(): void
    {
        $this->inner->expects(self::once())->method('publishMessageAttachmentsUpdated')->with($this->channelMessage);
        $this->purger->expects(self::once())->method('purgeMessage')->with($this->channelMessage->getId());
        $this->purger->expects(self::once())->method('purgeChannelPage')->with('comm', 'chan', 7);
        $this->purger->expects(self::once())->method('purgeChannelExtras')->with('comm', 'chan');

        $this->decorator->publishMessageAttachmentsUpdated($this->channelMessage);
    }

    public function testPinnedPurges(): void
    {
        $this->inner->expects(self::once())->method('publishMessagePinned')->with($this->channelMessage);
        $this->purger->expects(self::once())->method('purgeMessage')->with($this->channelMessage->getId());
        $this->purger->expects(self::once())->method('purgeChannelPage')->with('comm', 'chan', 7);
        $this->purger->expects(self::once())->method('purgeChannelExtras')->with('comm', 'chan');

        $this->decorator->publishMessagePinned($this->channelMessage);
    }

    public function testThreadMetaPurgesRootPage(): void
    {
        $this->channelMessage->incrementReplyCount();

        $this->inner->expects(self::once())->method('publishMessageThreadMeta')->with($this->channelMessage);
        $this->purger->expects(self::once())->method('purgeMessage')->with($this->channelMessage->getId());
        $this->purger->expects(self::once())->method('purgeChannelPage')->with('comm', 'chan', 7);
        $this->purger->expects(self::once())->method('purgeChannelExtras')->with('comm', 'chan');
        $this->purger->expects(self::once())->method('purgeMessageThread')->with($this->channelMessage->getId());

        $this->decorator->publishMessageThreadMeta($this->channelMessage);
    }

    public function testReplyUpdatePurgesParentThread(): void
    {
        $parent = $this->channelMessage;
        $reply = $this->makeChannelMessage(7, 'chan', 'comm');
        $reply->setParent($parent);

        $this->inner->expects(self::once())->method('publishMessageUpdated')->with($reply, '<p>x</p>');
        $this->purger->expects(self::once())->method('purgeMessageThread')->with($parent->getId());

        $this->decorator->publishMessageUpdated($reply, '<p>x</p>');
    }

    public function testRootWithRepliesUpdatePurgesOwnThread(): void
    {
        $this->channelMessage->incrementReplyCount();

        $this->inner->expects(self::once())->method('publishMessageUpdated')->with($this->channelMessage, '<p>x</p>');
        $this->purger->expects(self::once())->method('purgeMessageThread')->with($this->channelMessage->getId());

        $this->decorator->publishMessageUpdated($this->channelMessage, '<p>x</p>');
    }

    public function testRootWithoutRepliesUpdatePurgesNoThread(): void
    {
        $this->inner->expects(self::once())->method('publishMessageUpdated')->with($this->channelMessage, '<p>x</p>');
        $this->purger->expects(self::never())->method('purgeMessageThread');

        $this->decorator->publishMessageUpdated($this->channelMessage, '<p>x</p>');
    }

    public function testChannelActivityPurgesLatestPage(): void
    {
        $community = new Community();
        $community->setIdentifier('comm');

        $channel = new Channel();
        $channel->setIdentifier('chan');
        $channel->setCommunity($community);

        $latestPage = new MessagePage();
        $latestPage->setPageNumber(9);
        $latestPage->setChannel($channel);

        $this->pages->expects(self::once())->method('findLatestForChannel')->with($channel)->willReturn($latestPage);
        $this->inner->expects(self::once())->method('publishChannelActivity')->with($channel);
        $this->purger->expects(self::once())->method('purgeChannelPage')->with('comm', 'chan', 9);
        $this->purger->expects(self::once())->method('purgeChannelExtras')->with('comm', 'chan');

        $this->decorator->publishChannelActivity($channel);
    }

    public function testChannelActivityNoPageDoesNotPurge(): void
    {
        $channel = new Channel();
        $channel->setIdentifier('chan');

        $this->pages->expects(self::once())->method('findLatestForChannel')->with($channel)->willReturn(null);
        $this->inner->expects(self::once())->method('publishChannelActivity')->with($channel);
        $this->purger->expects(self::never())->method('purgeChannelPage');
        $this->purger->expects(self::never())->method('purgeChannelExtras');

        $this->decorator->publishChannelActivity($channel);
    }

    public function testConversationActivityPassesThroughWithoutPurging(): void
    {
        $conversation = new Conversation();

        $this->inner->expects(self::once())->method('publishConversationActivity')->with($conversation);
        $this->purger->expects(self::never())->method('purgeChannelPage');
        $this->purger->expects(self::never())->method('purgeChannelExtras');

        $this->decorator->publishConversationActivity($conversation);
    }
}
