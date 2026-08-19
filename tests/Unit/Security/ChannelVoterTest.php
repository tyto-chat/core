<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\User;
use App\Enum\Channel\ChannelRole;
use App\Enum\Community\CommunityRole;
use App\Enum\User\UserRole;
use App\Security\PermissionResolverInterface;
use App\Security\Voter\ChannelVoter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[AllowMockObjectsWithoutExpectations]
class ChannelVoterTest extends TestCase
{
    private PermissionResolverInterface&MockObject $permissions;
    private ChannelVoter $voter;

    #[\Override]
    protected function setUp(): void
    {
        $this->permissions = $this->createMock(PermissionResolverInterface::class);
        $this->voter = new ChannelVoter($this->permissions);
    }

    private function grant(?CommunityRole $communityRole, ?ChannelRole $effectiveChannelRole = null): void
    {
        $this->permissions->method('communityRole')->willReturn($communityRole);
        $this->permissions->method('effectiveChannelRole')->willReturn($effectiveChannelRole);
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $token->method('getRoleNames')->willReturn([]);

        return $token;
    }

    private function adminTokenFor(): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createMock(User::class));
        $token->method('getRoleNames')->willReturn([UserRole::Admin->value]);

        return $token;
    }

    private function anonToken(): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        return $token;
    }

    private function publicChannel(): Channel
    {
        return (new Channel())->setName('general')->setPrivate(false)->setCommunity(new Community());
    }

    private function privateChannel(): Channel
    {
        return (new Channel())->setName('private')->setPrivate(true)->setCommunity(new Community());
    }

    /** Non-private channel whose community is private (invite-only). */
    private function publicChannelInPrivateCommunity(): Channel
    {
        $community = (new Community())->setPrivate(true);

        return (new Channel())->setName('general')->setPrivate(false)->setCommunity($community);
    }

    public function testAbstainsForUnsupportedAttribute(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->anonToken(), new Channel(), ['UNSUPPORTED'])
        );
    }

    public function testAbstainsForNonChannelSubject(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->anonToken(), new \stdClass(), [ChannelVoter::VIEW])
        );
    }

    public function testGrantsViewToAnonymousOnPublicChannel(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->anonToken(), $this->publicChannel(), [ChannelVoter::VIEW])
        );
    }

    public function testDeniesViewToAnonymousOnPrivateChannel(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->anonToken(), $this->privateChannel(), [ChannelVoter::VIEW])
        );
    }

    #[DataProvider('provideWriteAttributes')]
    public function testDeniesWriteAttributesForAnonymousUser(string $attribute): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->anonToken(), $this->publicChannel(), [$attribute])
        );
    }

    /** @return iterable<string, array{string}> */
    public static function provideWriteAttributes(): iterable
    {
        yield 'POST' => [ChannelVoter::POST];
        yield 'MODERATE' => [ChannelVoter::MODERATE];
        yield 'VIEW_MEMBERS' => [ChannelVoter::VIEW_MEMBERS];
        yield 'JOIN_AUDIO' => [ChannelVoter::JOIN_AUDIO];
        yield 'REACT' => [ChannelVoter::REACT];
    }

    public function testDeniesPostToCommunityNonMemberOnPublicChannel(): void
    {
        $this->grant(null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->publicChannel(), [ChannelVoter::POST])
        );
    }

    public function testDeniesReplyToCommunityNonMemberOnPublicChannel(): void
    {
        $this->grant(null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->publicChannel(), [ChannelVoter::REPLY])
        );
    }

    public function testDeniesReactToCommunityNonMemberOnPublicChannel(): void
    {
        $this->grant(null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->publicChannel(), [ChannelVoter::REACT])
        );
    }

    public function testGrantsReactToCommunityMemberOnPublicChannel(): void
    {
        $this->grant(CommunityRole::Member);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->publicChannel(), [ChannelVoter::REACT])
        );
    }

    public function testGrantsReactToCommunityMemberOnReadonlyChannel(): void
    {
        $this->grant(CommunityRole::Member);
        $channel = (new Channel())->setName('readonly')->setPrivate(false)->setReadonly(true)->setCommunity(new Community());

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $channel, [ChannelVoter::REACT])
        );
    }

    public function testGrantsReactToChannelMemberOnPrivateChannel(): void
    {
        $this->grant(CommunityRole::Member, ChannelRole::Member);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->privateChannel(), [ChannelVoter::REACT])
        );
    }

    public function testDeniesReactToCommunityMemberOnPrivateChannelWithoutChannelRole(): void
    {
        $this->grant(CommunityRole::Member, null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->privateChannel(), [ChannelVoter::REACT])
        );
    }

    public function testGrantsReactToGlobalAdmin(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->adminTokenFor(), $this->publicChannel(), [ChannelVoter::REACT])
        );
    }

    public function testDeniesJoinAudioToCommunityNonMemberOnPublicChannel(): void
    {
        $this->grant(null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->publicChannel(), [ChannelVoter::JOIN_AUDIO])
        );
    }

    public function testGrantsJoinAudioToCommunityMemberOnPublicChannel(): void
    {
        $this->grant(CommunityRole::Member);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->publicChannel(), [ChannelVoter::JOIN_AUDIO])
        );
    }

    public function testGrantsJoinAudioToGlobalAdmin(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->adminTokenFor(), $this->publicChannel(), [ChannelVoter::JOIN_AUDIO])
        );
    }

    public function testGrantsJoinAudioToChannelMemberOnPrivateChannel(): void
    {
        $this->grant(CommunityRole::Member, ChannelRole::Member);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->privateChannel(), [ChannelVoter::JOIN_AUDIO])
        );
    }

    public function testDeniesJoinAudioToCommunityMemberOnPrivateChannelWithoutChannelRole(): void
    {
        $this->grant(CommunityRole::Member, null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->privateChannel(), [ChannelVoter::JOIN_AUDIO])
        );
    }

    public function testDeniesJoinAudioOnArchivedChannel(): void
    {
        $this->grant(CommunityRole::Member);
        $channel = $this->publicChannel()->setArchivedAt(new \DateTimeImmutable());

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $channel, [ChannelVoter::JOIN_AUDIO])
        );
    }

    public function testDeniesPostToReadonlyChannel(): void
    {
        $this->grant(CommunityRole::Member);
        $channel = (new Channel())->setName('readonly')->setPrivate(false)->setReadonly(true)->setCommunity(new Community());

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $channel, [ChannelVoter::POST])
        );
    }

    public function testDeniesReplyToReadonlyChannelWithoutFlag(): void
    {
        $this->grant(CommunityRole::Member);
        $channel = (new Channel())->setName('readonly')->setPrivate(false)->setReadonly(true)->setCommunity(new Community());

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $channel, [ChannelVoter::REPLY])
        );
    }

    public function testGrantsReplyToReadonlyChannelWhenRepliesAllowed(): void
    {
        $this->grant(CommunityRole::Member);
        $channel = (new Channel())->setName('readonly')->setPrivate(false)->setReadonly(true)->setAreReadonlyRepliesAllowed(true)->setCommunity(new Community());
        $this->permissions->expects(self::never())->method('effectiveChannelRole');

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $channel, [ChannelVoter::REPLY])
        );
    }

    public function testGrantsReplyToReadonlyChannelForModerator(): void
    {
        $this->grant(CommunityRole::Member, ChannelRole::Moderator);
        $channel = (new Channel())->setName('readonly')->setPrivate(true)->setReadonly(true)->setCommunity(new Community());

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $channel, [ChannelVoter::REPLY])
        );
    }

    public function testGrantsPostToPublicNonReadonlyChannelWithoutMembershipCheck(): void
    {
        $this->grant(CommunityRole::Member);
        $this->permissions->expects(self::never())->method('effectiveChannelRole');

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->publicChannel(), [ChannelVoter::POST])
        );
    }

    public function testDeniesPostToPrivateChannelWhenNotMember(): void
    {
        $this->grant(CommunityRole::Member, null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->privateChannel(), [ChannelVoter::POST])
        );
    }

    public function testGrantsPostToPrivateChannelForMember(): void
    {
        $this->grant(CommunityRole::Member, ChannelRole::Member);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->privateChannel(), [ChannelVoter::POST])
        );
    }

    public function testGrantsViewOnPublicChannelToNonChannelMember(): void
    {
        $this->grant(null, null);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->publicChannel(), [ChannelVoter::VIEW])
        );
    }

    public function testGrantsViewOnPrivateChannelToChannelMember(): void
    {
        $this->grant(CommunityRole::Member, ChannelRole::Member);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->privateChannel(), [ChannelVoter::VIEW])
        );
    }

    public function testDeniesViewOnPrivateChannelToStaleGrantHolderWhoLeftCommunity(): void
    {
        $this->grant(null, ChannelRole::Moderator);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->privateChannel(), [ChannelVoter::VIEW])
        );
    }

    public function testDeniesViewToAnonymousOnPublicChannelInPrivateCommunity(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->anonToken(), $this->publicChannelInPrivateCommunity(), [ChannelVoter::VIEW])
        );
    }

    public function testDeniesViewOnPublicChannelInPrivateCommunityToNonMember(): void
    {
        $this->grant(null, null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->publicChannelInPrivateCommunity(), [ChannelVoter::VIEW])
        );
    }

    public function testGrantsViewOnPublicChannelInPrivateCommunityToCommunityMember(): void
    {
        $this->grant(CommunityRole::Member);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->publicChannelInPrivateCommunity(), [ChannelVoter::VIEW])
        );
    }

    public function testDeniesPostOnPublicChannelInPrivateCommunityToNonMember(): void
    {
        $this->grant(null, null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->publicChannelInPrivateCommunity(), [ChannelVoter::POST])
        );
    }

    public function testDeniesViewOnPrivateChannelToNonChannelMember(): void
    {
        $this->grant(CommunityRole::Member, null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->privateChannel(), [ChannelVoter::VIEW])
        );
    }

    public function testDeniesModerateForRegularMember(): void
    {
        $this->grant(CommunityRole::Member, ChannelRole::Member);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->publicChannel(), [ChannelVoter::MODERATE])
        );
    }

    public function testGrantsModerateForModerator(): void
    {
        $this->grant(CommunityRole::Member, ChannelRole::Moderator);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->publicChannel(), [ChannelVoter::MODERATE])
        );
    }

    public function testGrantsViewMembersForPublicChannelChannelMember(): void
    {
        $this->grant(CommunityRole::Member, ChannelRole::Member);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->publicChannel(), [ChannelVoter::VIEW_MEMBERS])
        );
    }

    public function testDeniesViewMembersForPublicChannelNonChannelMember(): void
    {
        $this->grant(CommunityRole::Member, null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->publicChannel(), [ChannelVoter::VIEW_MEMBERS])
        );
    }

    public function testGrantsViewMembersForPrivateChannelModerator(): void
    {
        $this->grant(CommunityRole::Member, ChannelRole::Moderator);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->privateChannel(), [ChannelVoter::VIEW_MEMBERS])
        );
    }

    public function testGrantsViewMembersForPrivateChannelRegularMember(): void
    {
        $this->grant(CommunityRole::Member, ChannelRole::Member);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->privateChannel(), [ChannelVoter::VIEW_MEMBERS])
        );
    }

    #[DataProvider('provideAllAttributes')]
    public function testGrantsAllAttributesToAdminUser(string $attribute): void
    {
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->adminTokenFor(), $this->privateChannel(), [$attribute])
        );
    }

    /** @return iterable<string, array{string}> */
    public static function provideAllAttributes(): iterable
    {
        yield 'VIEW' => [ChannelVoter::VIEW];
        yield 'POST' => [ChannelVoter::POST];
        yield 'MODERATE' => [ChannelVoter::MODERATE];
        yield 'VIEW_MEMBERS' => [ChannelVoter::VIEW_MEMBERS];
        yield 'PIN' => [ChannelVoter::PIN];
    }

    #[DataProvider('provideAllAttributes')]
    public function testGrantsAllAttributesToCommunityAdmin(string $attribute): void
    {
        $this->grant(CommunityRole::Admin);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->publicChannel(), [$attribute])
        );
    }

    #[DataProvider('provideCommunityModeratorGrantedAttributes')]
    public function testGrantsCommunityModeratorChannelModSurface(string $attribute): void
    {
        $this->grant(CommunityRole::Moderator);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->publicChannel(), [$attribute])
        );
    }

    /** @return iterable<string, array{string}> */
    public static function provideCommunityModeratorGrantedAttributes(): iterable
    {
        yield 'VIEW' => [ChannelVoter::VIEW];
        yield 'POST' => [ChannelVoter::POST];
        yield 'VIEW_MEMBERS' => [ChannelVoter::VIEW_MEMBERS];
        yield 'PIN' => [ChannelVoter::PIN];
        yield 'MODERATE' => [ChannelVoter::MODERATE];
    }

    public function testGrantsViewToUserWithOnlyGroupMemberRole(): void
    {
        $this->grant(CommunityRole::Member, ChannelRole::Member);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->privateChannel(), [ChannelVoter::VIEW])
        );
    }

    public function testGrantsModerateToUserWithOnlyGroupModeratorRole(): void
    {
        $this->grant(CommunityRole::Member, ChannelRole::Moderator);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->privateChannel(), [ChannelVoter::MODERATE])
        );
    }

    public function testDeniesPinForRegularMember(): void
    {
        $this->grant(CommunityRole::Member, ChannelRole::Member);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->publicChannel(), [ChannelVoter::PIN])
        );
    }

    public function testGrantsPinForChannelModerator(): void
    {
        $this->grant(CommunityRole::Member, ChannelRole::Moderator);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->publicChannel(), [ChannelVoter::PIN])
        );
    }
}
