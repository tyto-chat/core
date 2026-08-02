<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\Notification\CreateNotificationDto;
use App\Entity\Community;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\Notification\NotificationType;
use App\Exception\Notification\NotificationNotFoundException;
use App\Repository\NotificationRepository;
use App\Security\SecurityContext;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Notification\NotificationService;
use App\Service\Notification\WebPushSenderInterface;
use App\Service\Realtime\RealtimePublisherInterface;
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
class NotificationServiceTest extends TestCase
{
    private NotificationRepository&MockObject $notificationRepository;
    private RealtimePublisherInterface&MockObject $publisher;
    private EntityManagerInterface&MockObject $entityManager;
    private Security&MockObject $security;
    private NotificationService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->notificationRepository = $this->createMock(NotificationRepository::class);
        $this->publisher = $this->createMock(RealtimePublisherInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->security = $this->createMock(Security::class);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));
        // Web Push disabled in unit tests → dispatchWebPush short-circuits.
        $webPushSender = $this->createMock(WebPushSenderInterface::class);
        $webPushSender->method('isConfigured')->willReturn(false);

        $this->service = new NotificationService(new SecurityContext($this->security, $this->createMock(CommunityMembershipServiceInterface::class)), $this->notificationRepository, $this->publisher, $bus, $webPushSender, $this->createMock(\Doctrine\Persistence\ManagerRegistry::class));
        $this->service->setEntityManager($this->entityManager);
        $this->service->setLogger(new NullLogger());
    }

    private function dto(): CreateNotificationDto
    {
        $user = $this->createMock(User::class);
        $community = $this->createMock(Community::class);

        return new CreateNotificationDto(
            recipient: $user,
            community: $community,
            channelIdentifier: 'general',
            communityIdentifier: 'test-community',
        );
    }

    public function testNewPersistsAndPublishesNotification(): void
    {
        $this->entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(Notification::class));
        $this->entityManager->expects(self::once())->method('flush');
        $this->publisher->expects(self::once())->method('publishNotification')->with(self::isInstanceOf(Notification::class));

        $result = $this->service->new($this->dto());

        self::assertSame(NotificationType::Mention, $result->getType());
    }

    public function testGetThrowsWhenNotFound(): void
    {
        $this->notificationRepository->method('findOneBy')->willReturn(null);

        $this->expectException(NotificationNotFoundException::class);
        $this->service->get(999);
    }

    public function testGetThrowsAccessDeniedWhenNotGranted(): void
    {
        $notification = $this->createMock(Notification::class);
        $this->notificationRepository->method('findOneBy')->willReturn($notification);
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->get(1);
    }

    public function testGetReturnsNotificationWhenGranted(): void
    {
        $notification = $this->createMock(Notification::class);
        $this->notificationRepository->method('findOneBy')->willReturn($notification);
        $this->security->method('isGranted')->willReturn(true);

        self::assertSame($notification, $this->service->get(1));
    }

    public function testGetAllForCommunityThrowsWhenNotAuthenticated(): void
    {
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->getAllForCommunity($this->createMock(Community::class));
    }

    public function testGetAllForCommunityReturnsNotifications(): void
    {
        $user = $this->createMock(User::class);
        $community = $this->createMock(Community::class);

        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($user);
        $this->notificationRepository->expects(self::once())->method('findByRecipientAndCommunity')->with($user, $community)->willReturn([]);

        $result = $this->service->getAllForCommunity($community);

        self::assertSame([], $result);
    }

    public function testMarkAsReadThrowsAccessDeniedWhenNotGranted(): void
    {
        $notification = $this->createMock(Notification::class);
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->markAsRead($notification);
    }

    public function testMarkAsReadSavesAndReturnsNotification(): void
    {
        $notification = new Notification();
        $this->security->method('isGranted')->willReturn(true);

        $this->entityManager->expects(self::once())->method('persist')->with($notification);
        $this->entityManager->expects(self::once())->method('flush');

        $result = $this->service->markAsRead($notification);

        self::assertSame($notification, $result);
        self::assertTrue($result->getIsRead());
    }

    public function testGetUnreadCountsThrowsWhenNotAuthenticated(): void
    {
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->getUnreadCounts();
    }

    public function testGetUnreadCountsReturnsDelegatesToRepositoryWhenAuthenticated(): void
    {
        $user = $this->createMock(User::class);
        $counts = ['community-1' => 3];

        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($user);
        $this->notificationRepository->expects(self::once())->method('countUnreadByRecipient')->with($user)->willReturn($counts);

        self::assertSame($counts, $this->service->getUnreadCounts());
    }

    public function testMarkAllAsReadThrowsWhenNotAuthenticated(): void
    {
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->markAllAsReadForCommunity($this->createMock(Community::class));
    }

    public function testMarkAllAsReadCallsRepository(): void
    {
        $user = $this->createMock(User::class);
        $community = $this->createMock(Community::class);

        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($user);
        $this->notificationRepository->expects(self::once())->method('markAllReadForCommunity')->with($user, $community);

        $this->service->markAllAsReadForCommunity($community);
    }
}
