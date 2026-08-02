<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Community;
use App\Entity\User;
use App\Enum\User\UserRole;
use App\Security\Voter\CommunityVoter;
use App\Service\Community\CommunityMembershipServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[AllowMockObjectsWithoutExpectations]
class CommunityVoterTest extends TestCase
{
    private CommunityMembershipServiceInterface&MockObject $communityMembershipService;
    private CommunityVoter $voter;

    #[\Override]
    protected function setUp(): void
    {
        $this->communityMembershipService = $this->createMock(CommunityMembershipServiceInterface::class);
        $this->voter = new CommunityVoter($this->communityMembershipService);
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    private function anonToken(): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        return $token;
    }

    private function adminToken(): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createMock(User::class));
        $token->method('getRoleNames')->willReturn([UserRole::Admin->value]);

        return $token;
    }

    private function community(bool $isPrivate): Community
    {
        $community = $this->createMock(Community::class);
        $community->method('isPrivate')->willReturn($isPrivate);

        return $community;
    }

    public function testAbstainsForUnsupportedAttribute(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->anonToken(), new Community(), ['UNSUPPORTED'])
        );
    }

    public function testAbstainsForNonCommunitySubject(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->anonToken(), new \stdClass(), [CommunityVoter::VIEW])
        );
    }

    public function testGrantsViewForPublicCommunityWithoutMembershipCheck(): void
    {
        $this->communityMembershipService->expects(self::never())->method('isMember');

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->anonToken(), $this->community(false), [CommunityVoter::VIEW])
        );
    }

    public function testDeniesViewForPrivateCommunityWhenAnonymous(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->anonToken(), $this->community(true), [CommunityVoter::VIEW])
        );
    }

    public function testDeniesViewForPrivateCommunityWhenNotMember(): void
    {
        $this->communityMembershipService->method('isMember')->willReturn(false);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->community(true), [CommunityVoter::VIEW])
        );
    }

    public function testGrantsViewForPrivateCommunityWhenMember(): void
    {
        $this->communityMembershipService->method('isMember')->willReturn(true);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->createMock(User::class)), $this->community(true), [CommunityVoter::VIEW])
        );
    }

    public function testGrantsViewForPrivateCommunityToGlobalAdmin(): void
    {
        // Global admin short-circuits without a membership lookup.
        $this->communityMembershipService->expects(self::never())->method('isMember');

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->adminToken(), $this->community(true), [CommunityVoter::VIEW])
        );
    }
}
