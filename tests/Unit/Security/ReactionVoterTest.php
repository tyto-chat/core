<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Reaction;
use App\Entity\User;
use App\Enum\User\UserRole;
use App\Security\Voter\ReactionVoter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[AllowMockObjectsWithoutExpectations]
class ReactionVoterTest extends TestCase
{
    private ReactionVoter $voter;

    #[\Override]
    protected function setUp(): void
    {
        $this->voter = new ReactionVoter();
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

    private function reactionBy(?User $author): Reaction
    {
        $reaction = $this->createMock(Reaction::class);
        $reaction->method('getCreatedBy')->willReturn($author);

        return $reaction;
    }

    public function testAbstainsForUnsupportedAttribute(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->anonToken(), new Reaction(), ['UNSUPPORTED'])
        );
    }

    public function testAbstainsForNonReactionSubject(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->anonToken(), new \stdClass(), [ReactionVoter::DELETE])
        );
    }

    public function testDeniesAnonymousUser(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->anonToken(), $this->reactionBy(null), [ReactionVoter::DELETE])
        );
    }

    public function testGrantsDeleteToReactionAuthor(): void
    {
        $user = $this->user(5);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($user), $this->reactionBy($user), [ReactionVoter::DELETE])
        );
    }

    public function testDeniesDeleteToNonAuthor(): void
    {
        $author = $this->user(5);
        $other = $this->user(99);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($other), $this->reactionBy($author), [ReactionVoter::DELETE])
        );
    }

    public function testGrantsDeleteToAdmin(): void
    {
        $author = $this->user(5);
        $admin = $this->user(99);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($admin, isAdmin: true), $this->reactionBy($author), [ReactionVoter::DELETE])
        );
    }

    public function testDeniesDeleteWhenReactionHasNoAuthor(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->user(1)), $this->reactionBy(null), [ReactionVoter::DELETE])
        );
    }
}
