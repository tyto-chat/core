<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Async\DisconnectVoiceParticipantMessage;
use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\ModerationAction;
use App\Entity\User;
use App\Enum\Community\CommunityRole;
use App\Enum\Moderation\ModerationActionType;
use App\Exception\Moderation\ImmuneTargetException;
use App\Repository\ModerationActionRepository;
use App\Security\SecurityContext;
use App\Service\Channel\ChannelMembershipServiceInterface;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Moderation\ModerationService;
use App\Service\Notification\NotificationServiceInterface;
use App\Service\Realtime\RealtimePublisherInterface;
use App\Service\Webhook\WebhookEmitterInterface;
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
class ModerationServiceTest extends TestCase
{
    private ModerationActionRepository&MockObject $repo;
    private CommunityMembershipServiceInterface&MockObject $membership;
    private NotificationServiceInterface&MockObject $notificationService;
    private EntityManagerInterface&MockObject $entityManager;
    private Security&MockObject $security;
    private RealtimePublisherInterface&MockObject $realtimePublisher;
    private MessageBusInterface&MockObject $messageBus;
    private ModerationService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->repo = $this->createMock(ModerationActionRepository::class);
        $this->membership = $this->createMock(CommunityMembershipServiceInterface::class);
        $this->notificationService = $this->createMock(NotificationServiceInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->security = $this->createMock(Security::class);
        $this->realtimePublisher = $this->createMock(RealtimePublisherInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->messageBus->method('dispatch')->willReturnCallback(
            static fn (object $m): Envelope => new Envelope($m),
        );

        $this->service = new ModerationService(
            new SecurityContext($this->security, $this->membership),
            $this->membership,
            $this->repo,
            $this->createMock(ChannelMembershipServiceInterface::class),
            $this->createMock(CommunityServiceInterface::class),
            $this->notificationService,
            $this->createMock(WebhookEmitterInterface::class),
            $this->realtimePublisher,
            $this->messageBus,
        );
        $this->service->setEntityManager($this->entityManager);
        $this->service->setLogger(new NullLogger());
    }

    private function authedUser(bool $admin = false): User
    {
        $user = $this->createMock(User::class);
        $user->method('hasRole')->willReturn($admin);
        $this->security->method('getUser')->willReturn($user);
        // The moderation ladder now runs through ModerationVoter (covered by
        // ModerationVoterTest). Here we only assert the service delegates to
        // isGranted: a global admin clears every MODERATION_* attribute, a
        // plain user clears none.
        $this->security->method('isGranted')->willReturnCallback(
            static fn (string $attribute): bool => 'ROLE_USER' === $attribute || $admin,
        );

        return $user;
    }

    /** @return User&MockObject */
    private function moderatableTarget(): User
    {
        $target = $this->createMock(User::class);
        $target->method('isBot')->willReturn(false);
        $target->method('hasRole')->willReturn(false);

        return $target;
    }

    public function testWarnDeniesNonGlobalMod(): void
    {
        $this->authedUser();
        $this->membership->method('findRole')->willReturn(CommunityRole::Member);

        $this->expectException(AccessDeniedException::class);
        $this->service->warn($this->createMock(Community::class), $this->moderatableTarget());
    }

    public function testWarnRejectsImmuneAdminTarget(): void
    {
        $this->authedUser(admin: true);
        $target = $this->createMock(User::class);
        $target->method('isBot')->willReturn(false);
        $target->method('hasRole')->willReturn(true); // global admin target

        $this->expectException(ImmuneTargetException::class);
        $this->service->warn($this->createMock(Community::class), $target);
    }

    public function testWarnPersistsAndNotifiesForAdmin(): void
    {
        $this->authedUser(admin: true);
        $community = $this->createMock(Community::class);
        $community->method('getIdentifier')->willReturn('c1');
        $this->membership->method('findRole')->willReturn(CommunityRole::Member);

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');
        $this->notificationService->expects(self::once())->method('new');

        $action = $this->service->warn($community, $this->moderatableTarget(), 'be nice');
        self::assertSame(ModerationActionType::Warn, $action->getType());
    }

    public function testServerBanDeniesNonAdmin(): void
    {
        $this->authedUser(admin: false);

        $this->expectException(AccessDeniedException::class);
        $this->service->serverBan($this->createMock(Community::class), $this->moderatableTarget(), null, null);
    }

    public function testLiftServerBanDeniesNonAdmin(): void
    {
        $this->authedUser(admin: false);
        $action = $this->createMock(ModerationAction::class);
        $action->method('getType')->willReturn(ModerationActionType::ServerBan);

        $this->expectException(AccessDeniedException::class);
        $this->service->lift($action);
    }

    public function testServerBanPublishesServerBannedEvent(): void
    {
        $this->authedUser(admin: true);

        $community = $this->createMock(Community::class);
        $community->method('getIdentifier')->willReturn('c1');

        $target = $this->createMock(User::class);
        $target->method('isBot')->willReturn(false);
        $target->method('hasRole')->willReturn(false);
        $target->method('getId')->willReturn(55);

        $this->membership->method('findRole')->willReturn(CommunityRole::Member);
        $this->repo->method('findActiveTimeoutsByUserAndCommunity')->willReturn([]);

        $this->realtimePublisher->expects(self::once())
            ->method('publishUserEvent')
            ->with(55, 'server.banned');

        $this->messageBus->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn (object $m): bool => $m instanceof DisconnectVoiceParticipantMessage
                && 55 === $m->userId
                && null === $m->channelId
                && null === $m->communityIdentifier))
            ->willReturn(new Envelope(new DisconnectVoiceParticipantMessage(55)));

        $this->service->serverBan($community, $target, null, null);
    }

    public function testTimeoutWithChannelDisconnectsFromChannel(): void
    {
        $this->authedUser(admin: true);

        $community = $this->createMock(Community::class);
        $community->method('getIdentifier')->willReturn('c1');

        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(7);

        $target = $this->moderatableTarget();
        $target->method('getId')->willReturn(42);

        $this->membership->method('findRole')->willReturn(CommunityRole::Member);

        $this->messageBus->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn (object $m): bool => $m instanceof DisconnectVoiceParticipantMessage
                && 42 === $m->userId
                && 7 === $m->channelId
                && null === $m->communityIdentifier))
            ->willReturn(new Envelope(new DisconnectVoiceParticipantMessage(42, channelId: 7)));

        $this->service->timeout($community, $target, null, new \DateTimeImmutable('+1 hour'), $channel);
    }

    public function testTimeoutWithoutChannelDisconnectsCommunityWide(): void
    {
        $this->authedUser(admin: true);

        $community = $this->createMock(Community::class);
        $community->method('getIdentifier')->willReturn('c1');

        $target = $this->moderatableTarget();
        $target->method('getId')->willReturn(42);

        $this->membership->method('findRole')->willReturn(CommunityRole::Member);

        $this->messageBus->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn (object $m): bool => $m instanceof DisconnectVoiceParticipantMessage
                && 42 === $m->userId
                && null === $m->channelId
                && 'c1' === $m->communityIdentifier))
            ->willReturn(new Envelope(new DisconnectVoiceParticipantMessage(42, communityIdentifier: 'c1')));

        $this->service->timeout($community, $target, null, new \DateTimeImmutable('+1 hour'));
    }
}
