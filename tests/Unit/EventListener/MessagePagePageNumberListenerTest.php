<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\MessagePage;
use App\EventListener\MessagePagePageNumberListener;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Conversation\ConversationServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class MessagePagePageNumberListenerTest extends TestCase
{
    private ChannelServiceInterface&MockObject $channelService;
    private ConversationServiceInterface&MockObject $conversationService;
    private MessagePagePageNumberListener $listener;

    #[\Override]
    protected function setUp(): void
    {
        $this->channelService = $this->createMock(ChannelServiceInterface::class);
        $this->conversationService = $this->createMock(ConversationServiceInterface::class);
        $this->listener = new MessagePagePageNumberListener($this->channelService, $this->conversationService);
    }

    private function makeEvent(object $entity): PrePersistEventArgs
    {
        return new PrePersistEventArgs($entity, $this->createMock(EntityManagerInterface::class));
    }

    public function testNonMessagePageEntityIsIgnored(): void
    {
        $this->channelService->expects(self::never())->method('nextPageNumber');
        $this->conversationService->expects(self::never())->method('nextPageNumber');

        $this->listener->prePersist($this->makeEvent(new \stdClass()));
    }

    public function testMessagePageWithNullContainerIsIgnored(): void
    {
        $this->channelService->expects(self::never())->method('nextPageNumber');
        $this->conversationService->expects(self::never())->method('nextPageNumber');

        $page = $this->createMock(MessagePage::class);
        $page->method('getChannel')->willReturn(null);
        $page->method('getConversation')->willReturn(null);

        $this->listener->prePersist($this->makeEvent($page));
    }

    public function testChannelPageGetsPageNumberFromChannelService(): void
    {
        $channel = $this->createMock(Channel::class);

        $page = $this->createMock(MessagePage::class);
        $page->method('getChannel')->willReturn($channel);
        $page->expects(self::once())->method('setPageNumber')->with(5);

        $this->channelService->method('nextPageNumber')->willReturn(5);

        $this->listener->prePersist($this->makeEvent($page));
    }

    public function testConversationPageGetsPageNumberFromConversationService(): void
    {
        $conversation = $this->createMock(Conversation::class);

        $page = $this->createMock(MessagePage::class);
        $page->method('getChannel')->willReturn(null);
        $page->method('getConversation')->willReturn($conversation);
        $page->expects(self::once())->method('setPageNumber')->with(3);

        $this->conversationService->method('nextPageNumber')->willReturn(3);

        $this->listener->prePersist($this->makeEvent($page));
    }
}
