<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Async\DisconnectVoiceParticipantMessage;
use App\Entity\Community;
use App\Entity\CommunityMember;
use App\Entity\MediaObject;
use App\Entity\User;
use App\Enum\Community\CommunityRole;
use App\Enum\User\UserRole;
use App\Exception\Community\AlreadyMemberException;
use App\Exception\Community\CannotLeaveLastAdminException;
use App\Exception\Community\CommunityNotFoundException;
use App\Exception\Community\NotAMemberException;
use App\Repository\CommunityRepository;
use App\Security\SecurityContext;
use App\Service\Channel\ChannelMembershipServiceInterface;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Community\CommunityPinServiceInterface;
use App\Service\Community\CommunityService;
use App\Service\HttpCache\CachePurgerInterface;
use App\Service\MediaObject\MediaObjectServiceInterface;
use App\Service\Message\WelcomeMessageServiceInterface;
use App\Service\Moderation\ModerationServiceInterface;
use App\Service\Realtime\RealtimePublisherInterface;
use App\Service\Search\SearchServiceInterface;
use App\Service\User\UserServiceInterface;
use App\Service\UserGroup\UserGroupServiceInterface;
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
class CommunityServiceTest extends TestCase
{
    private CommunityRepository&MockObject $communityRepository;
    private CommunityMembershipServiceInterface&MockObject $communityMembershipService;
    private MediaObjectServiceInterface&MockObject $mediaObjectService;
    private UserServiceInterface&MockObject $userService;
    private EntityManagerInterface&MockObject $entityManager;
    private Security&MockObject $security;
    private ModerationServiceInterface&MockObject $moderationService;
    private RealtimePublisherInterface&MockObject $realtimePublisher;
    private MessageBusInterface&MockObject $messageBus;
    private CachePurgerInterface&MockObject $cachePurger;
    private CommunityService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->communityRepository = $this->createMock(CommunityRepository::class);
        $this->communityMembershipService = $this->createMock(CommunityMembershipServiceInterface::class);
        $this->mediaObjectService = $this->createMock(MediaObjectServiceInterface::class);
        $this->userService = $this->createMock(UserServiceInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->security = $this->createMock(Security::class);
        $this->moderationService = $this->createMock(ModerationServiceInterface::class);
        $this->realtimePublisher = $this->createMock(RealtimePublisherInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->messageBus->method('dispatch')->willReturnCallback(
            static fn (object $m): Envelope => new Envelope($m),
        );

        $this->service = new CommunityService(
            new SecurityContext($this->security, $this->communityMembershipService),
            $this->communityMembershipService,
            $this->mediaObjectService,
            $this->communityRepository,
            $this->userService,
            $this->moderationService,
            $this->createMock(SearchServiceInterface::class),
            $this->createMock(ChannelMembershipServiceInterface::class),
            $this->createMock(UserGroupServiceInterface::class),
        );
        $this->service->setEntityManager($this->entityManager);
        $this->service->setCommunityPinService($this->createMock(CommunityPinServiceInterface::class));
        $this->service->setChannelService($this->createMock(ChannelServiceInterface::class));
        $this->service->setWelcomeMessageService($this->createMock(WelcomeMessageServiceInterface::class));
        $this->service->setRealtimePublisher($this->realtimePublisher);
        $this->service->setMessageBus($this->messageBus);
        $this->cachePurger = $this->createMock(CachePurgerInterface::class);
        $this->service->setCachePurger($this->cachePurger);
        $this->service->setLogger(new NullLogger());
    }

    private function community(bool $isPrivate = false, ?string $identifier = 'test-community'): Community
    {
        $community = $this->createMock(Community::class);
        $community->method('isPrivate')->willReturn($isPrivate);
        $community->method('getIdentifier')->willReturn($identifier);
        $community->method('getChannels')->willReturn(new ArrayCollection());

        return $community;
    }

    public function testNewThrowsForNonAdmin(): void
    {
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->new($this->createMock(\App\Dto\Community\CreateCommunityDto::class));
    }

    public function testGetThrowsWhenCommunityNotFound(): void
    {
        $this->communityRepository->method('findOneBy')->willReturn(null);

        $this->expectException(CommunityNotFoundException::class);
        $this->service->get(999);
    }

    public function testGetThrowsAccessDeniedWhenNotGranted(): void
    {
        $this->communityRepository->method('findOneBy')->willReturn($this->community());
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->get(1);
    }

    public function testGetMapsAuthenticatedViewDenialToNotFound(): void
    {
        $this->communityRepository->method('findOneBy')->willReturn($this->community());
        $this->security->method('isGranted')->willReturnCallback(
            static fn (string $attribute): bool => 'ROLE_USER' === $attribute,
        );

        $this->expectException(CommunityNotFoundException::class);
        $this->service->get(1);
    }

    public function testGetReturnsCommunityWhenGranted(): void
    {
        $community = $this->community();
        $this->communityRepository->method('findOneBy')->willReturn($community);
        $this->security->method('isGranted')->willReturn(true);

        self::assertSame($community, $this->service->get(1));
    }

    public function testGetAllReturnsPublicCommunitiesForUnauthenticatedUser(): void
    {
        $this->security->method('isGranted')->willReturn(false);
        $this->communityRepository->expects(self::once())->method('findPublic')->willReturn([]);

        $this->service->getAll();
    }

    public function testGetAllReturnsAllForAdmin(): void
    {
        $user = $this->createMock(User::class);
        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($user);
        $this->communityRepository->expects(self::once())->method('findAll')->willReturn([]);

        $this->service->getAll();
    }

    public function testGetAllReturnsPublicOrJoinedForRegularUser(): void
    {
        $user = $this->createMock(User::class);
        $this->security->method('isGranted')->willReturn(false);
        $this->security->method('getUser')->willReturn($user);
        $this->communityRepository->expects(self::once())->method('findPublicOrJoined')->with($user)->willReturn([]);

        $this->service->getAll();
    }

    public function testJoinThrowsWhenNotAuthenticated(): void
    {
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->join($this->community());
    }

    public function testJoinThrowsForPrivateCommunity(): void
    {
        $this->security->method('getUser')->willReturn($this->createMock(User::class));
        $this->security->method('isGranted')->willReturn(false); // not admin

        $this->expectException(AccessDeniedException::class);
        $this->service->join($this->community(isPrivate: true));
    }

    public function testJoinAllowsAdminToCreateMembership(): void
    {
        // Admins now join like anyone (real membership row) so they can opt in
        // to notifications; they also bypass the invite-only gate.
        $this->security->method('getUser')->willReturn($this->createMock(User::class));
        $this->security->method('isGranted')->willReturnMap([
            [UserRole::User->value, null, true],
            [UserRole::Admin->value, null, true],
        ]);
        $this->communityMembershipService->method('isMember')->willReturn(false);

        $this->entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(CommunityMember::class));
        $this->entityManager->expects(self::once())->method('flush');

        $this->service->join($this->community(isPrivate: true));
    }

    public function testJoinThrowsAlreadyMemberExceptionWhenAlreadyJoined(): void
    {
        $community = $this->community(identifier: 'my-community');
        $existingCommunity = $this->createMock(Community::class);
        $existingCommunity->method('getIdentifier')->willReturn('my-community');

        $this->security->method('getUser')->willReturn($this->createMock(User::class));
        $this->security->method('isGranted')->willReturnMap([
            [UserRole::User->value, null, true],
            [UserRole::Admin->value, null, false],
        ]);
        $this->communityMembershipService->method('isMember')->willReturn(true);

        $this->expectException(AlreadyMemberException::class);
        $this->service->join($community);
    }

    public function testJoinCreatesMembershipRecord(): void
    {
        $user = $this->createMock(User::class);
        $community = $this->community();

        $this->security->method('isGranted')->willReturnMap([
            [UserRole::User->value, null, true],
            [UserRole::Admin->value, null, false],
        ]);
        $this->security->method('getUser')->willReturn($user);
        $this->communityRepository->method('findJoined')->willReturn([]);

        $this->entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(CommunityMember::class));
        $this->entityManager->expects(self::once())->method('flush');

        $this->service->join($community);
    }

    public function testLeaveThrowsWhenNotAuthenticated(): void
    {
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->leave($this->community());
    }

    public function testLeaveThrowsNotAMemberExceptionForAdminWithoutRow(): void
    {
        // Admin viewing via the rail bypass but holding no membership row
        // cannot leave — there is nothing to leave.
        $this->security->method('getUser')->willReturn($this->createMock(User::class));
        $this->security->method('isGranted')->willReturnMap([
            [UserRole::User->value, null, true],
            [UserRole::Admin->value, null, true],
        ]);
        $this->communityMembershipService->method('findOneByUserAndCommunity')->willReturn(null);

        $this->expectException(NotAMemberException::class);
        $this->service->leave($this->community());
    }

    public function testLeaveThrowsNotAMemberExceptionWhenNotJoined(): void
    {
        $this->security->method('getUser')->willReturn($this->createMock(User::class));
        $this->security->method('isGranted')->willReturnMap([
            [UserRole::User->value, null, true],
            [UserRole::Admin->value, null, false],
        ]);
        $this->communityMembershipService->method('findOneByUserAndCommunity')->willReturn(null);

        $this->expectException(NotAMemberException::class);
        $this->service->leave($this->community());
    }

    public function testLeaveRemovesMembershipRecord(): void
    {
        $user = $this->createMock(User::class);
        $community = $this->community(identifier: 'my-community');
        $existingCommunity = $this->createMock(Community::class);
        $existingCommunity->method('getIdentifier')->willReturn('my-community');
        $member = $this->createMock(CommunityMember::class);
        // Non-admin role → skips the last-admin guard branch in leave().
        $member->method('getRole')->willReturn(CommunityRole::Member);

        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')->willReturnMap([
            [UserRole::User->value, null, true],
            [UserRole::Admin->value, null, false],
        ]);
        $this->communityRepository->method('findJoined')->willReturn([$existingCommunity]);
        $this->communityMembershipService->method('findOneByUserAndCommunity')->willReturn($member);

        $this->entityManager->expects(self::once())->method('remove')->with($member);
        $this->entityManager->expects(self::once())->method('flush');

        $this->service->leave($community);
    }

    public function testLeaveDispatchesVoiceDisconnect(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(9);
        $community = $this->community(identifier: 'c1');
        $member = $this->createMock(CommunityMember::class);
        $member->method('getRole')->willReturn(CommunityRole::Member);

        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')->willReturnMap([
            [UserRole::User->value, null, true],
            [UserRole::Admin->value, null, false],
        ]);
        $this->communityMembershipService->method('findOneByUserAndCommunity')->willReturn($member);

        $this->messageBus->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn (object $m): bool => $m instanceof DisconnectVoiceParticipantMessage
                && 9 === $m->userId
                && 'c1' === $m->communityIdentifier
                && null === $m->channelId))
            ->willReturn(new Envelope(new DisconnectVoiceParticipantMessage(9)));

        $this->service->leave($community);
    }

    public function testAddMemberThrowsForNonCommunityAdmin(): void
    {
        // Not a global admin, not a community admin.
        $this->security->method('isGranted')->willReturn(false);
        $this->security->method('getUser')->willReturn($this->createMock(User::class));
        $this->communityMembershipService->method('isAdmin')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->addMember($this->community(isPrivate: true), $this->createMock(User::class));
    }

    public function testAddMemberRejectsBannedUser(): void
    {
        $this->security->method('isGranted')->willReturn(true); // global admin → passes authz
        $this->moderationService->method('isBanned')->willReturn(true);

        $this->expectException(AccessDeniedException::class);
        $this->service->addMember($this->community(), $this->createMock(User::class));
    }

    public function testAddMemberReturnsExistingMembershipIdempotently(): void
    {
        $existing = $this->createMock(CommunityMember::class);
        $this->security->method('isGranted')->willReturn(true);
        $this->moderationService->method('isBanned')->willReturn(false);
        $this->communityMembershipService->method('findOneByUserAndCommunity')->willReturn($existing);

        // No new row persisted when the user already belongs to the community.
        $this->entityManager->expects(self::never())->method('persist');

        self::assertSame($existing, $this->service->addMember($this->community(), $this->createMock(User::class)));
    }

    public function testAddMemberCreatesMembershipWithGivenRole(): void
    {
        $this->security->method('isGranted')->willReturn(true);
        $this->moderationService->method('isBanned')->willReturn(false);
        $this->communityMembershipService->method('findOneByUserAndCommunity')->willReturn(null);

        $this->entityManager->expects(self::once())->method('persist')
            ->with(self::callback(static fn (CommunityMember $m): bool => CommunityRole::Moderator === $m->getRole()));
        $this->entityManager->expects(self::once())->method('flush');

        $member = $this->service->addMember($this->community(), $this->createMock(User::class), CommunityRole::Moderator);

        self::assertSame(CommunityRole::Moderator, $member->getRole());
    }

    public function testAddMemberWithSendWelcomeFalseDoesNotPostWelcomeMessage(): void
    {
        // Re-wire with a strict mock so we can assert sendIfConfigured is never called.
        $welcomeMessageService = $this->createMock(WelcomeMessageServiceInterface::class);
        $welcomeMessageService->expects(self::never())->method('sendIfConfigured');
        $this->service->setWelcomeMessageService($welcomeMessageService);

        $this->security->method('isGranted')->willReturn(true);
        $this->moderationService->method('isBanned')->willReturn(false);
        $this->communityMembershipService->method('findOneByUserAndCommunity')->willReturn(null);

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $member = $this->service->addMember(
            $this->community(),
            $this->createMock(User::class),
            CommunityRole::Member,
            false,
        );

        self::assertSame(CommunityRole::Member, $member->getRole());
    }

    public function testDeleteThrowsForNonAdmin(): void
    {
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->delete($this->community());
    }

    public function testDeleteRemovesCommunity(): void
    {
        $community = $this->community();
        $this->security->method('isGranted')->willReturn(true);

        $this->entityManager->expects(self::once())->method('remove')->with($community);
        $this->entityManager->expects(self::once())->method('flush');

        $this->service->delete($community);
    }

    public function testDeletePurgesCommunityDetailCache(): void
    {
        // delete() never publishes structure (topic dies with the entity), so
        // the purge must happen directly — else the cached detail Get keeps
        // serving the deleted community until TTL expiry.
        $community = $this->community(identifier: 'doomed-community');
        $this->security->method('isGranted')->willReturn(true);

        $this->cachePurger->expects(self::once())->method('purgeCommunityDetail')->with('doomed-community');

        $this->service->delete($community);
    }

    public function testSetLogoThrowsForNonCommunityAdmin(): void
    {
        $community = $this->community();
        // Not a global admin, and not a community admin of this community.
        $this->security->method('isGranted')->willReturn(false);
        $this->security->method('getUser')->willReturn($this->createMock(User::class));
        $this->communityMembershipService->method('isAdmin')->willReturn(false);

        // The gate must fire before any media work happens.
        $this->mediaObjectService->expects(self::never())->method('prepare');

        $this->expectException(AccessDeniedException::class);
        $this->service->setLogo($community, $this->createMock(MediaObject::class));
    }

    public function testSetLogoAllowsCommunityAdmin(): void
    {
        $community = $this->community();
        $logo = $this->createMock(MediaObject::class);
        $this->security->method('isGranted')->willReturn(false);
        $this->security->method('getUser')->willReturn($this->createMock(User::class));
        $this->communityMembershipService->method('isAdmin')->willReturn(true);

        // prepare() runs only after the community-admin gate passes, so a single
        // call proves the authz check let this caller through.
        $this->mediaObjectService->expects(self::once())->method('prepare')->with($logo, 'logo');

        $this->service->setLogo($community, $logo);
    }

    public function testSetLogoAllowsGlobalAdmin(): void
    {
        $community = $this->community();
        $logo = $this->createMock(MediaObject::class);
        // Global admin short-circuits the community-admin check.
        $this->security->method('isGranted')->willReturn(true);

        $this->mediaObjectService->expects(self::once())->method('prepare')->with($logo, 'logo');

        $this->service->setLogo($community, $logo);
    }

    public function testSetLogoPurgesCommunityDetailCache(): void
    {
        // setLogo has no publishCommunityStructureChanged call (audit gap,
        // task-3), so the purge must happen directly here rather than via
        // CachePurgingStructurePublisher.
        $community = $this->community(identifier: 'logo-community');
        $logo = $this->createMock(MediaObject::class);
        $this->security->method('isGranted')->willReturn(true);

        $this->cachePurger->expects(self::once())->method('purgeCommunityDetail')->with('logo-community');
        $this->realtimePublisher->expects(self::never())->method('publishCommunityStructureChanged');

        $this->service->setLogo($community, $logo);
    }

    public function testUpdateMemberRolePublishesStructureAndRoleEvent(): void
    {
        $community = $this->createMock(Community::class);
        $community->method('getIdentifier')->willReturn('c1');
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(9);
        $member = $this->createMock(CommunityMember::class);
        $member->method('getCommunity')->willReturn($community);
        $member->method('getUser')->willReturn($user);
        $member->method('getRole')->willReturn(CommunityRole::Member);
        $this->security->method('isGranted')->willReturn(true);

        $this->realtimePublisher->expects(self::once())->method('publishCommunityStructureChanged')->with($community);
        $this->realtimePublisher->expects(self::once())->method('publishUserEvent')
            ->with(9, 'role.changed', ['communityIdentifier' => 'c1']);

        $this->service->updateMemberRole($member, CommunityRole::Moderator);
    }

    public function testUpdateMemberRoleBlocksDemotingLastAdmin(): void
    {
        $community = $this->createMock(Community::class);
        $member = $this->createMock(CommunityMember::class);
        $member->method('getCommunity')->willReturn($community);
        $member->method('getRole')->willReturn(CommunityRole::Admin);
        $this->security->method('isGranted')->willReturn(true);
        $this->communityMembershipService->method('countByRole')->willReturn(1);

        $this->expectException(CannotLeaveLastAdminException::class);
        $this->service->updateMemberRole($member, CommunityRole::Member);
    }

    public function testKickMemberDisconnectsFromCommunityVoice(): void
    {
        $community = $this->createMock(Community::class);
        $community->method('getIdentifier')->willReturn('c1');
        $target = $this->createMock(User::class);
        $target->method('getId')->willReturn(33);
        $member = $this->createMock(CommunityMember::class);
        $this->security->method('isGranted')->willReturn(true);
        $this->communityMembershipService->method('findOneByUserAndCommunity')->willReturn($member);

        $this->messageBus->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn (object $m): bool => $m instanceof DisconnectVoiceParticipantMessage
                && 33 === $m->userId
                && null === $m->channelId
                && 'c1' === $m->communityIdentifier))
            ->willReturn(new Envelope(new DisconnectVoiceParticipantMessage(33, communityIdentifier: 'c1')));

        $this->service->kickMember($community, $target);
    }
}
