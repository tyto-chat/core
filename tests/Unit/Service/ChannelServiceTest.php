<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Async\DisconnectVoiceParticipantMessage;
use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\MessagePage;
use App\Entity\User;
use App\Exception\Channel\ChannelNotFoundException;
use App\Exception\Message\MessagePageNotFoundException;
use App\Repository\ChannelRepository;
use App\Repository\MessagePageRepository;
use App\Security\SecurityContext;
use App\Service\Channel\ChannelService;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\MediaObject\MediaObjectServiceInterface;
use App\Service\Message\MessageServiceInterface;
use App\Service\Realtime\RealtimePublisherInterface;
use App\Service\Search\SearchServiceInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Tests\Stub\InMemoryChannelParticipantStore;
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
class ChannelServiceTest extends TestCase
{
    private ChannelRepository&MockObject $channelRepository;
    private MessagePageRepository&MockObject $messagePageRepository;
    private MessageServiceInterface&MockObject $messageService;
    private RealtimePublisherInterface&MockObject $realtimePublisher;
    private MessageBusInterface&MockObject $messageBus;
    private InMemoryChannelParticipantStore $participantStore;
    private SearchServiceInterface&MockObject $searchService;
    private SettingsServiceInterface&MockObject $settings;
    private EntityManagerInterface&MockObject $entityManager;
    private Security&MockObject $security;
    private ChannelService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->channelRepository = $this->createMock(ChannelRepository::class);
        $this->messagePageRepository = $this->createMock(MessagePageRepository::class);
        $this->messageService = $this->createMock(MessageServiceInterface::class);
        $this->realtimePublisher = $this->createMock(RealtimePublisherInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->messageBus->method('dispatch')->willReturnCallback(
            static fn (object $m): Envelope => new Envelope($m),
        );
        $this->participantStore = new InMemoryChannelParticipantStore();
        $this->searchService = $this->createMock(SearchServiceInterface::class);
        $this->settings = $this->createMock(SettingsServiceInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->security = $this->createMock(Security::class);

        $this->service = new ChannelService(
            new SecurityContext($this->security, $this->createMock(CommunityMembershipServiceInterface::class)),
            $this->channelRepository,
            $this->messagePageRepository,
            $this->messageService,
            voiceEnabled: true,
            realtimePublisher: $this->realtimePublisher,
            messageBus: $this->messageBus,
            participantStore: $this->participantStore,
            searchService: $this->searchService,
            settings: $this->settings,
            mediaObjectService: $this->createMock(MediaObjectServiceInterface::class),
        );
        $this->service->setEntityManager($this->entityManager);
        $this->service->setLogger(new NullLogger());
    }

    public function testGetThrowsWhenChannelNotFound(): void
    {
        $this->channelRepository->method('findOneBy')->willReturn(null);

        $this->expectException(ChannelNotFoundException::class);
        $this->service->get(999);
    }

    public function testGetThrowsAccessDeniedWhenNotGranted(): void
    {
        $this->channelRepository->method('findOneBy')->willReturn($this->createMock(Channel::class));
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->get(1);
    }

    public function testGetReturnsChannelWhenGranted(): void
    {
        $channel = $this->createMock(Channel::class);
        $this->channelRepository->method('findOneBy')->willReturn($channel);
        $this->security->method('isGranted')->willReturn(true);

        self::assertSame($channel, $this->service->get(1));
    }

    public function testGetMapsAuthenticatedViewDenialToNotFound(): void
    {
        $channel = $this->createMock(Channel::class);
        $this->channelRepository->method('findOneBy')->willReturn($channel);
        $this->security->method('isGranted')->willReturnCallback(
            static fn (string $attribute): bool => 'ROLE_USER' === $attribute,
        );

        $this->expectException(ChannelNotFoundException::class);
        $this->service->get(1);
    }

    public function testDeleteDispatchesVoiceDisconnectPerParticipant(): void
    {
        $community = $this->createMock(Community::class);
        $channel = $this->createMock(Channel::class);
        $channel->method('getCommunity')->willReturn($community);
        $channel->method('getId')->willReturn(12);
        $this->security->method('isGranted')->willReturn(true);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);
        $this->participantStore->save($user, $channel, 'user-7');

        $this->messageBus->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn (object $m): bool => $m instanceof DisconnectVoiceParticipantMessage
                && 7 === $m->userId
                && 12 === $m->channelId
                && null === $m->communityIdentifier))
            ->willReturn(new Envelope(new DisconnectVoiceParticipantMessage(7)));

        $this->service->delete($channel);
    }

    public function testGetChannelPageThrowsAccessDeniedWhenNotGranted(): void
    {
        $channel = $this->createMock(Channel::class);
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->getChannelPage($channel, 1);
    }

    public function testGetChannelPageThrowsWhenPageNotFound(): void
    {
        $channel = $this->createMock(Channel::class);
        $this->security->method('isGranted')->willReturn(true);
        $this->messagePageRepository->method('findByChannelAndPageNumber')->willReturn(null);

        $this->expectException(MessagePageNotFoundException::class);
        $this->service->getChannelPage($channel, 99);
    }

    public function testGetChannelPageHydratesPage(): void
    {
        $channel = $this->createMock(Channel::class);
        $page = new MessagePage();

        $this->security->method('isGranted')->willReturn(true);
        $this->messagePageRepository->method('findByChannelAndPageNumber')->willReturn($page);
        $this->messageService->method('getRootsByPage')->willReturn([]);

        $result = $this->service->getChannelPage($channel, 1);

        self::assertSame(0, $result->getMessageCount());
    }
}
