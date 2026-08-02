<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\ModerationAction;
use App\Entity\User;
use App\Enum\Moderation\ModerationActionType;
use App\Enum\User\UserRole;
use App\Security\Voter\ModerationVoter;
use App\Service\Channel\ChannelMembershipServiceInterface;
use App\Service\Community\CommunityMembershipServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[AllowMockObjectsWithoutExpectations]
class ModerationVoterTest extends TestCase
{
    private CommunityMembershipServiceInterface&MockObject $membership;
    private ChannelMembershipServiceInterface&MockObject $channelService;
    private ModerationVoter $voter;
    private Community $community;

    #[\Override]
    protected function setUp(): void
    {
        $this->membership = $this->createMock(CommunityMembershipServiceInterface::class);
        $this->channelService = $this->createMock(ChannelMembershipServiceInterface::class);
        $this->voter = new ModerationVoter($this->membership, $this->channelService);
        $this->community = $this->createMock(Community::class);
    }

    private function token(bool $admin = false): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createMock(User::class));
        $token->method('getRoleNames')->willReturn($admin ? [UserRole::Admin->value] : []);

        return $token;
    }

    private function vote(string $attribute, object $subject, TokenInterface $token): int
    {
        return $this->voter->vote($token, $subject, [$attribute]);
    }

    public function testServerBanGrantedForAdmin(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(ModerationVoter::SERVER_BAN, $this->community, $this->token(admin: true)));
    }

    public function testServerBanDeniedForCommunityAdmin(): void
    {
        $this->membership->method('isAdmin')->willReturn(true);
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(ModerationVoter::SERVER_BAN, $this->community, $this->token()));
    }

    public function testBanGrantedForCommunityAdmin(): void
    {
        $this->membership->method('isAdmin')->willReturn(true);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(ModerationVoter::BAN, $this->community, $this->token()));
    }

    public function testBanDeniedForCommunityModerator(): void
    {
        $this->membership->method('isAdmin')->willReturn(false);
        $this->membership->method('isModerator')->willReturn(true);
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(ModerationVoter::BAN, $this->community, $this->token()));
    }

    public function testWarnGrantedForCommunityModerator(): void
    {
        $this->membership->method('isAdmin')->willReturn(false);
        $this->membership->method('isModerator')->willReturn(true);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(ModerationVoter::WARN, $this->community, $this->token()));
    }

    public function testWarnDeniedForPlainMember(): void
    {
        $this->membership->method('isAdmin')->willReturn(false);
        $this->membership->method('isModerator')->willReturn(false);
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(ModerationVoter::WARN, $this->community, $this->token()));
    }

    public function testTimeoutOnChannelGrantedForDirectChannelModerator(): void
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getCommunity')->willReturn($this->community);
        // Not a global mod, but a direct channel moderator.
        $this->membership->method('isAdmin')->willReturn(false);
        $this->membership->method('isModerator')->willReturn(false);
        $this->channelService->method('isChannelModerator')->willReturn(true);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(ModerationVoter::TIMEOUT, $channel, $this->token()));
    }

    public function testTimeoutOnChannelDeniedForNonModerator(): void
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getCommunity')->willReturn($this->community);
        $this->membership->method('isAdmin')->willReturn(false);
        $this->membership->method('isModerator')->willReturn(false);
        $this->channelService->method('isChannelModerator')->willReturn(false);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(ModerationVoter::TIMEOUT, $channel, $this->token()));
    }

    public function testTimeoutOnCommunityDeniedForChannelOnlyModerator(): void
    {
        // Community-wide timeout requires global mod; a mere channel moderator
        // (no community role) must not pass.
        $this->membership->method('isAdmin')->willReturn(false);
        $this->membership->method('isModerator')->willReturn(false);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(ModerationVoter::TIMEOUT, $this->community, $this->token()));
    }

    public function testLiftServerBanDeniedForCommunityAdmin(): void
    {
        $action = $this->createMock(ModerationAction::class);
        $action->method('getType')->willReturn(ModerationActionType::ServerBan);
        $action->method('getCommunity')->willReturn($this->community);
        $this->membership->method('isAdmin')->willReturn(true);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(ModerationVoter::LIFT, $action, $this->token()));
    }

    public function testLiftServerBanGrantedForGlobalAdmin(): void
    {
        $action = $this->createMock(ModerationAction::class);
        $action->method('getType')->willReturn(ModerationActionType::ServerBan);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(ModerationVoter::LIFT, $action, $this->token(admin: true)));
    }

    public function testLiftCommunityTimeoutGrantedForCommunityModerator(): void
    {
        $action = $this->createMock(ModerationAction::class);
        $action->method('getType')->willReturn(ModerationActionType::Timeout);
        $action->method('getCommunity')->willReturn($this->community);
        $this->membership->method('isAdmin')->willReturn(false);
        $this->membership->method('isModerator')->willReturn(true);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(ModerationVoter::LIFT, $action, $this->token()));
    }

    public function testViewDeniedForOutsider(): void
    {
        $action = $this->createMock(ModerationAction::class);
        $action->method('getCommunity')->willReturn($this->community);
        $action->method('getChannel')->willReturn(null);
        $this->membership->method('isAdmin')->willReturn(false);
        $this->membership->method('isModerator')->willReturn(false);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(ModerationVoter::VIEW, $action, $this->token()));
    }
}
