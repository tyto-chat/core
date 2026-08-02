<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\UserGroup\CreateUserGroupDto;
use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Enum\Channel\ChannelRole;
use App\Exception\UserGroup\ChannelNotInGroupCommunityException;
use App\Exception\UserGroup\UserGroupNotFoundException;
use App\Repository\GroupChannelPermissionRepository;
use App\Repository\UserGroupMemberRepository;
use App\Repository\UserGroupRepository;
use App\Security\SecurityContext;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Notification\NotificationServiceInterface;
use App\Service\UserGroup\UserGroupService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AllowMockObjectsWithoutExpectations]
class UserGroupServiceTest extends TestCase
{
    private UserGroupRepository&MockObject $groupRepo;
    private UserGroupMemberRepository&MockObject $memberRepo;
    private CommunityMembershipServiceInterface&MockObject $membership;
    private EntityManagerInterface&MockObject $entityManager;
    private Security&MockObject $security;
    private UserGroupService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->groupRepo = $this->createMock(UserGroupRepository::class);
        $this->memberRepo = $this->createMock(UserGroupMemberRepository::class);
        $this->membership = $this->createMock(CommunityMembershipServiceInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->security = $this->createMock(Security::class);

        $this->service = new UserGroupService(
            new SecurityContext($this->security, $this->membership),
            $this->membership,
            $this->groupRepo,
            $this->memberRepo,
            $this->createMock(GroupChannelPermissionRepository::class),
            $this->createMock(NotificationServiceInterface::class),
            $this->createMock(\App\Service\Realtime\RealtimePublisherInterface::class),
            $this->createMock(\App\Service\Channel\ChannelMembershipServiceInterface::class),
        );
        $this->service->setEntityManager($this->entityManager);
        $this->service->setLogger(new NullLogger());
    }

    public function testNewDeniesNonCommunityAdmin(): void
    {
        $this->security->method('isGranted')->willReturn(false);
        $this->membership->method('isAdmin')->willReturn(false);
        $this->security->method('getUser')->willReturn($this->createMock(User::class));

        $this->expectException(AccessDeniedException::class);
        $this->service->new($this->createMock(Community::class), new CreateUserGroupDto('Mods'));
    }

    public function testNewCreatesGroupForCommunityAdmin(): void
    {
        $this->security->method('isGranted')->willReturn(true); // ROLE_ADMIN short-circuit

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $group = $this->service->new($this->createMock(Community::class), new CreateUserGroupDto('Mods', 'icon', '#fff', true));
        self::assertSame('Mods', $group->getName());
    }

    public function testGetByIdentifierThrowsNotFoundWhenMissing(): void
    {
        $this->security->method('isGranted')->willReturn(true);
        $this->groupRepo->method('findOneBy')->willReturn(null);

        $this->expectException(UserGroupNotFoundException::class);
        $this->service->getByIdentifier('mods', $this->createMock(Community::class));
    }

    public function testSetChannelPermissionRejectsChannelFromAnotherCommunity(): void
    {
        $this->security->method('isGranted')->willReturn(true); // ROLE_ADMIN short-circuit

        $groupCommunity = $this->createMock(Community::class);
        $groupCommunity->method('getId')->willReturn(1);
        $group = $this->createMock(UserGroup::class);
        $group->method('getCommunity')->willReturn($groupCommunity);

        $otherCommunity = $this->createMock(Community::class);
        $otherCommunity->method('getId')->willReturn(2);
        $channel = $this->createMock(Channel::class);
        $channel->method('getCommunity')->willReturn($otherCommunity);
        $channel->method('getIdentifier')->willReturn('other-channel');

        $this->expectException(ChannelNotInGroupCommunityException::class);
        $this->service->setChannelPermission($group, $channel, ChannelRole::Moderator);
    }

    public function testGetByIdentifierThrowsNotFoundWhenViewDenied(): void
    {
        // Authenticated (ROLE_USER) but not granted VIEW on the (hidden) group.
        $this->security->method('isGranted')->willReturnCallback(
            static fn (string $attr): bool => 'ROLE_USER' === $attr,
        );
        $this->groupRepo->method('findOneBy')->willReturn($this->createMock(UserGroup::class));

        $this->expectException(UserGroupNotFoundException::class);
        $this->service->getByIdentifier('hidden', $this->createMock(Community::class));
    }
}
