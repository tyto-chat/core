<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Community;
use App\Entity\CommunityEmoji;
use App\Entity\User;
use App\Enum\User\UserRole;
use App\Security\Voter\CommunityEmojiVoter;
use App\Service\Community\CommunityMembershipServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[AllowMockObjectsWithoutExpectations]
class CommunityEmojiVoterTest extends TestCase
{
    private CommunityMembershipServiceInterface&MockObject $communityMembershipService;
    private CommunityEmojiVoter $voter;

    #[\Override]
    protected function setUp(): void
    {
        $this->communityMembershipService = $this->createMock(CommunityMembershipServiceInterface::class);
        $this->voter = new CommunityEmojiVoter($this->communityMembershipService);
    }

    /** @param list<string> $roles */
    private function tokenFor(?User $user, array $roles = []): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $token->method('getRoleNames')->willReturn($roles);

        return $token;
    }

    private function community(bool $isPrivate): Community&MockObject
    {
        $community = $this->createMock(Community::class);
        $community->method('isPrivate')->willReturn($isPrivate);

        return $community;
    }

    public function testAbstainsForUnsupportedAttribute(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->tokenFor(null), $this->community(false), ['UNRELATED'])
        );
    }

    public function testAbstainsForUnsupportedSubject(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->tokenFor(null), new \stdClass(), [CommunityEmojiVoter::VIEW])
        );
    }

    public function testViewGrantedToAnonymousOnPublicCommunity(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor(null), $this->community(false), [CommunityEmojiVoter::VIEW])
        );
    }

    public function testViewDeniedToAnonymousOnPrivateCommunity(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor(null), $this->community(true), [CommunityEmojiVoter::VIEW])
        );
    }

    public function testViewGrantedToMemberOnPrivateCommunity(): void
    {
        $user = $this->createMock(User::class);
        $community = $this->community(true);
        $this->communityMembershipService->method('isMember')->willReturn(true);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($user), $community, [CommunityEmojiVoter::VIEW])
        );
    }

    public function testViewDeniedToNonMemberOnPrivateCommunity(): void
    {
        $user = $this->createMock(User::class);
        $community = $this->community(true);
        $this->communityMembershipService->method('isMember')->willReturn(false);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($user), $community, [CommunityEmojiVoter::VIEW])
        );
    }

    public function testGlobalAdminBypassesEverything(): void
    {
        $user = $this->createMock(User::class);
        $community = $this->community(true);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($user, [UserRole::Admin->value]), $community, [CommunityEmojiVoter::MANAGE])
        );
    }

    public function testManageGrantedToCommunityAdmin(): void
    {
        $user = $this->createMock(User::class);
        $community = $this->community(false);
        $this->communityMembershipService->method('isAdmin')->willReturn(true);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($user), $community, [CommunityEmojiVoter::MANAGE])
        );
    }

    public function testManageDeniedToRegularMember(): void
    {
        $user = $this->createMock(User::class);
        $community = $this->community(false);
        $this->communityMembershipService->method('isAdmin')->willReturn(false);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($user), $community, [CommunityEmojiVoter::MANAGE])
        );
    }

    public function testManageDeniedToAnonymous(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor(null), $this->community(false), [CommunityEmojiVoter::MANAGE])
        );
    }

    public function testEmojiSubjectInheritsCommunityCheck(): void
    {
        $community = $this->community(false);
        $emoji = $this->createMock(CommunityEmoji::class);
        $emoji->method('getCommunity')->willReturn($community);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor(null), $emoji, [CommunityEmojiVoter::VIEW])
        );
    }
}
