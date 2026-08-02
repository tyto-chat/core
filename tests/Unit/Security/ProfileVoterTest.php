<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Profile;
use App\Entity\User;
use App\Enum\User\UserRole;
use App\Security\Voter\ProfileVoter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[AllowMockObjectsWithoutExpectations]
class ProfileVoterTest extends TestCase
{
    private ProfileVoter $voter;

    #[\Override]
    protected function setUp(): void
    {
        $this->voter = new ProfileVoter();
    }

    private function tokenFor(User $user, bool $isAdmin = false): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $token->method('getRoleNames')->willReturn($isAdmin ? [UserRole::Admin->value] : [UserRole::User->value]);

        return $token;
    }

    private function anonToken(): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        return $token;
    }

    private function user(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    private function profileOwnedBy(?User $owner): Profile
    {
        $profile = $this->createMock(Profile::class);
        $profile->method('getUser')->willReturn($owner);

        return $profile;
    }

    public function testAbstainsForUnsupportedAttribute(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->anonToken(), new Profile(), ['UNSUPPORTED'])
        );
    }

    public function testAbstainsForNonProfileSubject(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->anonToken(), new \stdClass(), [ProfileVoter::VIEW])
        );
    }

    public function testDeniesAnonymousUserForView(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->anonToken(), $this->profileOwnedBy(null), [ProfileVoter::VIEW])
        );
    }

    public function testDeniesAnonymousUserForUpdate(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->anonToken(), $this->profileOwnedBy(null), [ProfileVoter::UPDATE])
        );
    }

    public function testGrantsViewToAnyAuthenticatedUser(): void
    {
        $profile = $this->profileOwnedBy($this->user(5));

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->user(99)), $profile, [ProfileVoter::VIEW])
        );
    }

    public function testGrantsUpdateToProfileOwner(): void
    {
        $owner = $this->user(5);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($owner), $this->profileOwnedBy($owner), [ProfileVoter::UPDATE])
        );
    }

    public function testDeniesUpdateToNonOwner(): void
    {
        $owner = $this->user(5);
        $other = $this->user(99);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($other), $this->profileOwnedBy($owner), [ProfileVoter::UPDATE])
        );
    }

    public function testGrantsUpdateToAdmin(): void
    {
        $owner = $this->user(5);
        $admin = $this->user(99);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($admin, isAdmin: true), $this->profileOwnedBy($owner), [ProfileVoter::UPDATE])
        );
    }

    public function testDeniesUpdateWhenProfileHasNoOwner(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->user(1)), $this->profileOwnedBy(null), [ProfileVoter::UPDATE])
        );
    }
}
