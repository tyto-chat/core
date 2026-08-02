<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use ApiPlatform\Metadata\IriConverterInterface;
use App\Dto\Message\UpdateMessageDto;
use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\Message;
use App\Entity\MessagePage;
use App\Entity\MessageRevision;
use App\Entity\User;
use App\Enum\Message\MessageKind;
use App\Exception\Message\CannotReplyToReplyException;
use App\Exception\Message\MessageAlreadyPinnedException;
use App\Exception\Message\MessageNotFoundException;
use App\Exception\Message\MessageNotPinnedException;
use App\Exception\Message\PinNotAllowedException;
use App\Exception\Message\ThreadNotAllowedException;
use App\Exception\Message\TooManyPinnedMessagesException;
use App\Repository\MessagePageRepository;
use App\Repository\MessageRepository;
use App\Security\SecurityContext;
use App\Service\Channel\ChannelMembershipServiceInterface;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\HttpCache\CachePurgerInterface;
use App\Service\MediaObject\MediaObjectServiceInterface;
use App\Service\Message\MessageNotificationDispatcherInterface;
use App\Service\Message\MessageService;
use App\Service\Message\MessageServiceInterface;
use App\Service\Moderation\ModerationServiceInterface;
use App\Service\Realtime\RealtimePublisherInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Service\Webhook\WebhookEmitterInterface;
use App\Settings\Settings;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AllowMockObjectsWithoutExpectations]
class MessageServiceTest extends TestCase
{
    private MessageRepository&MockObject $messageRepository;
    private ChannelServiceInterface&MockObject $channelService;
    private ConversationServiceInterface&MockObject $conversationService;
    private MessageNotificationDispatcherInterface&MockObject $notificationDispatcher;
    private RealtimePublisherInterface&MockObject $publisher;
    private IriConverterInterface&MockObject $iriConverter;
    private EntityManagerInterface&MockObject $entityManager;
    private MessageBusInterface&MockObject $messageBus;
    private Security&MockObject $security;
    private CachePurgerInterface&MockObject $cachePurger;
    private MessagePageRepository&MockObject $messagePages;
    private MessageService $service;

    /**
     * Build a Message mock with Kind defaulted to Standard. PHPUnit refuses
     * to auto-generate return values for enum-typed methods (cannot double
     * an enum), so every test that hits a code path calling getKind() needs
     * the stub.
     */
    private function mockMessage(): Message&MockObject
    {
        $message = $this->createMock(Message::class);
        $message->method('getKind')->willReturn(MessageKind::Standard);

        return $message;
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->messageRepository = $this->createMock(MessageRepository::class);
        $this->channelService = $this->createMock(ChannelServiceInterface::class);
        $this->conversationService = $this->createMock(ConversationServiceInterface::class);
        $this->notificationDispatcher = $this->createMock(MessageNotificationDispatcherInterface::class);
        $this->publisher = $this->createMock(RealtimePublisherInterface::class);
        $this->iriConverter = $this->createMock(IriConverterInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $fn) => $fn());
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->messageBus->method('dispatch')->willReturnCallback(static fn (object $m) => new Envelope($m));
        $this->security = $this->createMock(Security::class);
        $this->cachePurger = $this->createMock(CachePurgerInterface::class);
        $this->messagePages = $this->createMock(MessagePageRepository::class);

        $membership = $this->createMock(CommunityMembershipServiceInterface::class);
        $this->service = new MessageService(
            new SecurityContext($this->security, $membership),
            $membership,
            $this->messageRepository,
            $this->channelService,
            $this->createMock(ChannelMembershipServiceInterface::class),
            $this->conversationService,
            $this->notificationDispatcher,
            $this->publisher,
            $this->iriConverter,
            $this->createMock(MediaObjectServiceInterface::class),
            $this->createMock(ModerationServiceInterface::class),
            $this->createMock(WebhookEmitterInterface::class),
            $this->messageBus,
            $this->cachePurger,
            $this->messagePages,
            $this->settingsStub(),
        );
        $this->service->setEntityManager($this->entityManager);
        $this->service->setLogger(new NullLogger());
    }

    private function settingsStub(): SettingsServiceInterface
    {
        $settings = $this->createMock(SettingsServiceInterface::class);
        $settings->method('get')->willReturnCallback(fn ($def) => match ($def->key) {
            Settings::maxAttachmentsPerMessage()->key => 10,
            default => null,
        });

        return $settings;
    }

    public function testSendToChannelThrowsWhenAccessDenied(): void
    {
        $channel = $this->createMock(Channel::class);
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->sendToChannel($channel, 'hello');
    }

    public function testSendToChannelCreatesPageWhenNoneExists(): void
    {
        $channel = $this->createMock(Channel::class);
        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($this->createMock(User::class));
        $this->channelService->method('findLatestPage')->willReturn(null);
        $this->iriConverter->method('getIriFromResource')->willReturn('/api/v1/messages/1');

        $this->entityManager->expects(self::exactly(3))->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $result = $this->service->sendToChannel($channel, 'hello');

        self::assertSame('hello', $result->getText());
    }

    public function testSendToChannelReusesExistingPageWhenBelowCapacity(): void
    {
        $channel = $this->createMock(Channel::class);
        $page = $this->createMock(MessagePage::class);
        $this->messageRepository->method('countRootsInPage')->willReturn(5);

        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($this->createMock(User::class));
        $this->channelService->method('findLatestPage')->willReturn($page);
        $this->iriConverter->method('getIriFromResource')->willReturn('/api/v1/messages/1');

        $this->entityManager->expects(self::exactly(2))->method('persist');

        $this->service->sendToChannel($channel, 'hello');
    }

    public function testSendToChannelCreatesNewPageWhenExistingPageIsFull(): void
    {
        $channel = $this->createMock(Channel::class);
        $fullPage = $this->createMock(MessagePage::class);
        $this->messageRepository->method('countRootsInPage')->willReturn(MessagePage::PAGE_SIZE);

        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($this->createMock(User::class));
        $this->channelService->method('findLatestPage')->willReturn($fullPage);
        $this->iriConverter->method('getIriFromResource')->willReturn('/api/v1/messages/1');

        $this->entityManager->expects(self::exactly(3))->method('persist');

        $this->service->sendToChannel($channel, 'hello');
    }

    public function testSendToChannelPublishesChannelActivity(): void
    {
        $channel = $this->createMock(Channel::class);
        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($this->createMock(User::class));
        $this->channelService->method('findLatestPage')->willReturn(null);
        $this->iriConverter->method('getIriFromResource')->willReturn('/api/v1/messages/1');
        $this->entityManager->method('persist');

        $this->publisher->expects(self::once())->method('publishChannelActivity')->with($channel);

        $this->service->sendToChannel($channel, 'hello');
    }

    public function testSendToChannelPurgesCacheForSystemMessageWithoutPublishing(): void
    {
        $community = $this->createMock(Community::class);
        $community->method('getIdentifier')->willReturn('acme');

        $channel = $this->createMock(Channel::class);
        $channel->method('getIdentifier')->willReturn('general');
        $channel->method('getCommunity')->willReturn($community);

        $latestPage = $this->createMock(MessagePage::class);
        $latestPage->method('getPageNumber')->willReturn(3);

        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($this->createMock(User::class));
        $this->channelService->method('findLatestPage')->willReturn(null);
        $this->iriConverter->method('getIriFromResource')->willReturn('/api/v1/messages/1');
        $this->messagePages->expects(self::once())->method('findLatestForChannel')->with($channel)->willReturn($latestPage);

        $this->publisher->expects(self::never())->method('publishChannelActivity');
        $this->cachePurger->expects(self::once())
            ->method('purgeChannelPage')
            ->with('acme', 'general', 3);
        $this->cachePurger->expects(self::once())
            ->method('purgeChannelExtras')
            ->with('acme', 'general');

        $this->service->sendToChannel($channel, 'welcome', [], MessageKind::System);
    }

    public function testUpdateAddsRevisionAndPublishes(): void
    {
        $message = $this->mockMessage();
        $message->expects(self::once())->method('addRevision');
        $message->expects(self::once())->method('setText');
        $this->security->method('isGranted')->willReturn(true);

        $this->entityManager->expects(self::atLeastOnce())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $dto = new UpdateMessageDto();
        $dto->text = 'updated text';

        $this->publisher->expects(self::once())->method('publishMessageUpdated')->with($message, 'updated text');

        $this->service->update($message, $dto);
    }

    public function testUpdateThrowsAccessDeniedWhenNotGranted(): void
    {
        $message = $this->mockMessage();
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->update($message, new UpdateMessageDto());
    }

    public function testGetThrowsWhenNotFound(): void
    {
        $this->messageRepository->method('findOneBy')->willReturn(null);

        $this->expectException(MessageNotFoundException::class);
        $this->service->getById('non-existent-id');
    }

    public function testGetThrowsAccessDeniedWhenNotGranted(): void
    {
        $message = $this->mockMessage();
        $this->messageRepository->method('findOneBy')->willReturn($message);
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->getById('some-id');
    }

    public function testGetReturnsMessageWhenGranted(): void
    {
        $message = $this->mockMessage();
        $this->messageRepository->method('findOneBy')->willReturn($message);
        $this->security->method('isGranted')->willReturn(true);

        self::assertSame($message, $this->service->getById('some-id'));
    }

    public function testDeleteThrowsWhenNotGranted(): void
    {
        $message = $this->mockMessage();
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->delete($message);
    }

    public function testDeleteSoftDeletesAndPublishes(): void
    {
        $actor = $this->createMock(User::class);
        $message = $this->mockMessage();
        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($actor);
        $message->method('getAttachments')->willReturn(new ArrayCollection());

        $message->expects(self::once())->method('setDeleted')->with(true);
        $message->expects(self::once())->method('setDeletedAt')->with(self::isInstanceOf(\DateTime::class));
        $message->expects(self::once())->method('setDeletedBy')->with($actor);
        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');
        $this->publisher->expects(self::once())->method('publishMessageDeleted')->with($message);

        $this->service->delete($message);
    }

    public function testDeletingReplyKeepsParentReplyCount(): void
    {
        // Soft-deleted replies stay counted — the panel renders them in
        // place as "This message was deleted." so the count still matches
        // the visible row total.
        $parent = new Message();
        $parent->incrementReplyCount();
        $parent->incrementReplyCount(); // start at 2

        $reply = $this->mockMessage();
        $reply->method('getAttachments')->willReturn(new ArrayCollection());
        $reply->method('getParent')->willReturn($parent);

        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($this->createMock(User::class));
        $this->publisher->expects(self::once())->method('publishMessageDeleted');
        $this->publisher->expects(self::never())->method('publishMessageThreadMeta');

        $this->service->delete($reply);

        self::assertSame(2, $parent->getReplyCount());
    }

    public function testHydrateTextSetsTextFromLastRevision(): void
    {
        $message = new Message();
        $revision = new MessageRevision();
        $revision->setText('the latest revision');
        $message->addRevision($revision);

        $this->service->hydrateText($message);
        self::assertSame('the latest revision', $message->getText());
    }

    public function testHydrateTextSetsNullWhenDeleted(): void
    {
        $message = new Message();
        $revision = new MessageRevision();
        $revision->setText('original');
        $message->addRevision($revision);
        $message->setDeleted(true);

        $this->service->hydrateText($message);
        self::assertNull($message->getText());
    }

    public function testHydrateTextSetsNullWhenNoRevision(): void
    {
        $message = new Message();
        $this->service->hydrateText($message);
        self::assertNull($message->getText());
    }

    public function testPinThrowsForDmMessage(): void
    {
        $message = $this->mockMessage();
        $message->method('getChannel')->willReturn(null);
        $message->method('getParent')->willReturn(null);

        $this->expectException(PinNotAllowedException::class);
        $this->service->pin($message);
    }

    public function testPinThrowsForReply(): void
    {
        $message = $this->mockMessage();
        $channel = $this->createMock(Channel::class);
        $message->method('getChannel')->willReturn($channel);
        $message->method('getParent')->willReturn(new Message());

        $this->expectException(PinNotAllowedException::class);
        $this->service->pin($message);
    }

    public function testPinThrowsWhenAlreadyPinned(): void
    {
        $message = $this->mockMessage();
        $channel = $this->createMock(Channel::class);
        $message->method('getChannel')->willReturn($channel);
        $message->method('getParent')->willReturn(null);
        $message->method('isPinned')->willReturn(true);
        $this->security->method('isGranted')->willReturn(true);

        $this->expectException(MessageAlreadyPinnedException::class);
        $this->service->pin($message);
    }

    public function testPinThrowsWhenCapReached(): void
    {
        $message = $this->mockMessage();
        $channel = $this->createMock(Channel::class);
        $message->method('getChannel')->willReturn($channel);
        $message->method('getParent')->willReturn(null);
        $message->method('isPinned')->willReturn(false);
        $message->method('getRevisions')->willReturn(new ArrayCollection());
        $this->security->method('isGranted')->willReturn(true);
        $this->messageRepository->method('countPinnedForChannel')->willReturn(MessageServiceInterface::MAX_PINNED_PER_CHANNEL);

        $this->expectException(TooManyPinnedMessagesException::class);
        $this->service->pin($message);
    }

    public function testPinSetsTimestampAndPublishes(): void
    {
        $channel = $this->createMock(Channel::class);
        $msgMock = $this->mockMessage();
        $msgMock->method('getChannel')->willReturn($channel);
        $msgMock->method('getParent')->willReturn(null);
        $msgMock->method('isPinned')->willReturn(false);
        $msgMock->method('getRevisions')->willReturn(new ArrayCollection());
        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($this->createMock(User::class));
        $this->messageRepository->method('countPinnedForChannel')->willReturn(0);

        $msgMock->expects(self::once())->method('setPinnedAt')->with(self::isInstanceOf(\DateTimeImmutable::class));
        $this->publisher->expects(self::once())->method('publishMessagePinned')->with($msgMock);

        $this->service->pin($msgMock);
    }

    public function testUnpinThrowsForDmMessage(): void
    {
        $message = $this->mockMessage();
        $message->method('getChannel')->willReturn(null);

        $this->expectException(PinNotAllowedException::class);
        $this->service->unpin($message);
    }

    public function testUnpinThrowsWhenNotPinned(): void
    {
        $message = $this->mockMessage();
        $channel = $this->createMock(Channel::class);
        $message->method('getChannel')->willReturn($channel);
        $message->method('isPinned')->willReturn(false);
        $this->security->method('isGranted')->willReturn(true);

        $this->expectException(MessageNotPinnedException::class);
        $this->service->unpin($message);
    }

    public function testUnpinClearsAndPublishes(): void
    {
        $message = $this->mockMessage();
        $channel = $this->createMock(Channel::class);
        $message->method('getChannel')->willReturn($channel);
        $message->method('isPinned')->willReturn(true);
        $message->method('getRevisions')->willReturn(new ArrayCollection());
        $this->security->method('isGranted')->willReturn(true);

        $message->expects(self::once())->method('setPinnedAt')->with(null);
        $message->expects(self::once())->method('setPinnedBy')->with(null);
        $this->publisher->expects(self::once())->method('publishMessagePinned')->with($message);

        $this->service->unpin($message);
    }

    public function testFindPinnedForChannelHydratesText(): void
    {
        $channel = $this->createMock(Channel::class);
        $this->security->method('isGranted')->willReturn(true);

        $msg = new Message();
        $revision = new MessageRevision();
        $revision->setText('hello pinned');
        $msg->addRevision($revision);

        $this->messageRepository->method('findPinnedForChannel')->willReturn([$msg]);
        $result = $this->service->findPinnedForChannel($channel);
        self::assertCount(1, $result);
        self::assertSame('hello pinned', $result[0]->getText());
    }

    public function testReplyThrowsForDmMessage(): void
    {
        $root = $this->mockMessage();
        $root->method('getChannel')->willReturn(null);

        $this->expectException(ThreadNotAllowedException::class);
        $this->service->reply($root, 'hi');
    }

    public function testReplyThrowsWhenRootIsItselfReply(): void
    {
        $root = $this->mockMessage();
        $channel = $this->createMock(Channel::class);
        $root->method('getChannel')->willReturn($channel);
        $root->method('getParent')->willReturn(new Message());

        $this->expectException(CannotReplyToReplyException::class);
        $this->service->reply($root, 'hi');
    }

    public function testReplyThrowsAccessDenied(): void
    {
        $root = $this->mockMessage();
        $channel = $this->createMock(Channel::class);
        $root->method('getChannel')->willReturn($channel);
        $root->method('getParent')->willReturn(null);
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->reply($root, 'hi');
    }

    public function testReplyCreatesIncrementsAndPublishes(): void
    {
        $page = $this->createMock(MessagePage::class);
        $channel = $this->createMock(Channel::class);

        $rootMock = $this->mockMessage();
        $rootMock->method('getChannel')->willReturn($channel);
        $rootMock->method('getParent')->willReturn(null);
        $rootMock->method('getPage')->willReturn($page);
        $rootMock->expects(self::once())->method('incrementReplyCount');
        $rootMock->expects(self::once())->method('setLastReplyAt')->with(self::isInstanceOf(\DateTimeImmutable::class));

        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($this->createMock(User::class));
        $this->iriConverter->method('getIriFromResource')->willReturn('/api/v1/messages/x');
        $this->publisher->expects(self::once())->method('publishMessageThreadMeta')->with($rootMock);

        $reply = $this->service->reply($rootMock, 'a reply');
        self::assertSame($page, $reply->getPage());
        self::assertSame($rootMock, $reply->getParent());
        self::assertSame('a reply', $reply->getText());
    }

    public function testFindThreadRepliesHydratesText(): void
    {
        $root = $this->mockMessage();
        $this->security->method('isGranted')->willReturn(true);

        $reply = new Message();
        $revision = new MessageRevision();
        $revision->setText('thread body');
        $reply->addRevision($revision);

        $this->messageRepository->method('findChildren')->willReturn([$reply]);
        $result = $this->service->findThreadReplies($root);
        self::assertCount(1, $result);
        self::assertSame('thread body', $result[0]->getText());
    }
}
