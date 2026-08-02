<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Community;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Enum\User\UserRole;
use App\Repository\UserGroupMemberRepository;
use App\Security\Voter\UserGroupVoter;
use App\Service\Community\CommunityMembershipServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[AllowMockObjectsWithoutExpectations]
class UserGroupVoterTest extends TestCase
{
    private UserGroupMemberRepository&MockObject $userGroupMemberRepository;
    private CommunityMembershipServiceInterface&MockObject $communityMembershipService;
    private UserGroupVoter $voter;

    #[\Override]
    protected function setUp(): void
    {
        $this->userGroupMemberRepository = $this->createMock(UserGroupMemberRepository::class);
        $this->communityMembershipService = $this->createMock(CommunityMembershipServiceInterface::class);
        $this->voter = new UserGroupVoter($this->userGroupMemberRepository, $this->communityMembershipService);
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $token->method('getRoleNames')->willReturn([]);

        return $token;
    }

    private function adminToken(): TokenInterface
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

    private function group(bool $isHidden = false, ?int $ownerId = null): UserGroup
    {
        $group = $this->createMock(UserGroup::class);
        $group->method('getCommunity')->willReturn(new Community());
        $group->method('getOwnerId')->willReturn($ownerId);
        $group->method('getIsHidden')->willReturn($isHidden);

        return $group;
    }

    private function user(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    public function testAbstainsForUnsupportedAttribute(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->anonToken(), $this->group(), ['UNSUPPORTED'])
        );
    }

    public function testAbstainsForNonUserGroupSubject(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->anonToken(), new \stdClass(), [UserGroupVoter::VIEW])
        );
    }

    #[DataProvider('provideAllAttributes')]
    public function testDeniesAllToAnonymous(string $attribute): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->anonToken(), $this->group(isHidden: false), [$attribute])
        );
    }

    /** @return iterable<string, array{string}> */
    public static function provideAllAttributes(): iterable
    {
        yield 'VIEW' => [UserGroupVoter::VIEW];
        yield 'VIEW_MEMBERS' => [UserGroupVoter::VIEW_MEMBERS];
        yield 'MANAGE' => [UserGroupVoter::MANAGE];
    }

    #[DataProvider('provideAllAttributes')]
    public function testGrantsAllToGlobalAdmin(string $attribute): void
    {
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->adminToken(), $this->group(isHidden: true), [$attribute])
        );
    }

    #[DataProvider('provideAllAttributes')]
    public function testGrantsAllToCommunityAdmin(string $attribute): void
    {
        $this->communityMembershipService->method('isAdmin')->willReturn(true);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->user(1)), $this->group(isHidden: true), [$attribute])
        );
    }

    #[DataProvider('provideAllAttributes')]
    public function testGrantsAllToOwner(string $attribute): void
    {
        $owner = $this->user(7);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($owner), $this->group(isHidden: true, ownerId: 7), [$attribute])
        );
    }

    public function testGrantsViewToGroupMember(): void
    {
        $this->userGroupMemberRepository->method('isMember')->willReturn(true);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->user(1)), $this->group(isHidden: true), [UserGroupVoter::VIEW])
        );
    }

    public function testGrantsViewMembersToGroupMember(): void
    {
        $this->userGroupMemberRepository->method('isMember')->willReturn(true);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->user(1)), $this->group(isHidden: true), [UserGroupVoter::VIEW_MEMBERS])
        );
    }

    public function testDeniesManageToGroupMember(): void
    {
        $this->userGroupMemberRepository->method('isMember')->willReturn(true);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->user(1)), $this->group(isHidden: false), [UserGroupVoter::MANAGE])
        );
    }

    public function testGrantsViewOnNonHiddenGroupToCommunityMember(): void
    {
        $this->userGroupMemberRepository->method('isMember')->willReturn(false);
        $this->communityMembershipService->method('isMember')->willReturn(true);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->user(1)), $this->group(isHidden: false), [UserGroupVoter::VIEW])
        );
    }

    public function testDeniesViewOnNonHiddenGroupToNonCommunityMember(): void
    {
        $this->userGroupMemberRepository->method('isMember')->willReturn(false);
        $this->communityMembershipService->method('isMember')->willReturn(false);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->user(1)), $this->group(isHidden: false), [UserGroupVoter::VIEW])
        );
    }

    public function testDeniesViewOnHiddenGroupToNonMember(): void
    {
        $this->userGroupMemberRepository->method('isMember')->willReturn(false);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->user(1)), $this->group(isHidden: true), [UserGroupVoter::VIEW])
        );
    }

    public function testDeniesViewMembersToNonMember(): void
    {
        $this->userGroupMemberRepository->method('isMember')->willReturn(false);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->user(1)), $this->group(isHidden: false), [UserGroupVoter::VIEW_MEMBERS])
        );
    }

    public function testDeniesManageToNonMember(): void
    {
        $this->userGroupMemberRepository->method('isMember')->willReturn(false);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->user(1)), $this->group(isHidden: false), [UserGroupVoter::MANAGE])
        );
    }
}
