<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\User;
use App\Enum\Channel\ChannelRole;
use App\Enum\Community\CommunityRole;
use App\Repository\ChannelMemberRepository;
use App\Repository\CommunityMemberRepository;
use App\Repository\GroupChannelPermissionRepository;
use App\Security\PermissionResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class PermissionResolverTest extends TestCase
{
    private CommunityMemberRepository&MockObject $communityMembers;
    private ChannelMemberRepository&MockObject $channelMembers;
    private GroupChannelPermissionRepository&MockObject $groupChannelPermissions;
    private PermissionResolver $resolver;

    #[\Override]
    protected function setUp(): void
    {
        $this->communityMembers = $this->createMock(CommunityMemberRepository::class);
        $this->channelMembers = $this->createMock(ChannelMemberRepository::class);
        $this->groupChannelPermissions = $this->createMock(GroupChannelPermissionRepository::class);
        $this->resolver = new PermissionResolver(
            $this->communityMembers,
            $this->channelMembers,
            $this->groupChannelPermissions,
        );
    }

    private function user(int $id = 1): User
    {
        $user = new User();
        new \ReflectionProperty(User::class, 'id')->setValue($user, $id);

        return $user;
    }

    private function community(int $id = 10): Community
    {
        $community = new Community();
        new \ReflectionProperty(Community::class, 'id')->setValue($community, $id);

        return $community;
    }

    private function channelIn(Community $community, int $id): Channel
    {
        $channel = (new Channel())->setName('ch'.$id)->setCommunity($community);
        new \ReflectionProperty(Channel::class, 'id')->setValue($channel, $id);

        return $channel;
    }

    public function testRoleAnswersDeriveFromCommunityRole(): void
    {
        $this->communityMembers->method('findRole')->willReturn(CommunityRole::Moderator);
        $this->channelMembers->method('findRolesForUserInCommunity')->willReturn([]);
        $this->groupChannelPermissions->method('findRolesForUserInCommunity')->willReturn([]);

        $user = $this->user();
        $community = $this->community();

        self::assertTrue($this->resolver->isMember($user, $community));
        self::assertFalse($this->resolver->isCommunityAdmin($user, $community));
        self::assertTrue($this->resolver->isCommunityModerator($user, $community));
    }

    public function testEffectiveChannelRoleTakesHighestOfDirectAndGroup(): void
    {
        $this->communityMembers->method('findRole')->willReturn(CommunityRole::Member);
        $this->channelMembers->method('findRolesForUserInCommunity')->willReturn([5 => ChannelRole::Member]);
        $this->groupChannelPermissions->method('findRolesForUserInCommunity')->willReturn([5 => ChannelRole::Moderator]);

        $community = $this->community();

        self::assertSame(
            ChannelRole::Moderator,
            $this->resolver->effectiveChannelRole($this->user(), $this->channelIn($community, 5)),
        );
    }

    public function testEffectiveChannelRoleNullWithoutAnyGrant(): void
    {
        $this->communityMembers->method('findRole')->willReturn(CommunityRole::Member);
        $this->channelMembers->method('findRolesForUserInCommunity')->willReturn([]);
        $this->groupChannelPermissions->method('findRolesForUserInCommunity')->willReturn([]);

        $community = $this->community();

        self::assertNull($this->resolver->effectiveChannelRole($this->user(), $this->channelIn($community, 5)));
    }

    public function testLoadsOncePerUserAndCommunity(): void
    {
        $this->communityMembers->expects(self::once())->method('findRole')->willReturn(CommunityRole::Member);
        $this->channelMembers->expects(self::once())->method('findRolesForUserInCommunity')->willReturn([]);
        $this->groupChannelPermissions->expects(self::once())->method('findRolesForUserInCommunity')->willReturn([]);

        $user = $this->user();
        $community = $this->community();

        $this->resolver->isMember($user, $community);
        $this->resolver->directChannelRoles($user, $community);
        $this->resolver->effectiveChannelRole($user, $this->channelIn($community, 5));
    }

    public function testResetForgetsMemo(): void
    {
        $this->communityMembers->expects(self::exactly(2))->method('findRole')->willReturn(null);
        $this->channelMembers->method('findRolesForUserInCommunity')->willReturn([]);
        $this->groupChannelPermissions->method('findRolesForUserInCommunity')->willReturn([]);

        $user = $this->user();
        $community = $this->community();

        $this->resolver->isMember($user, $community);
        $this->resolver->reset();
        $this->resolver->isMember($user, $community);
    }
}
