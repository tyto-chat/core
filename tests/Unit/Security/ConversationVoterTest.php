<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Conversation;
use App\Entity\User;
use App\Enum\User\UserRole;
use App\Security\Voter\ConversationVoter;
use App\Service\Conversation\ConversationServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[AllowMockObjectsWithoutExpectations]
class ConversationVoterTest extends TestCase
{
    private ConversationServiceInterface&MockObject $conversationService;
    private ConversationVoter $voter;

    #[\Override]
    protected function setUp(): void
    {
        $this->conversationService = $this->createMock(ConversationServiceInterface::class);
        $this->voter = new ConversationVoter($this->conversationService);
    }

    private function tokenFor(?User $user, bool $isAdmin = false): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $token->method('getRoleNames')->willReturn($isAdmin ? [UserRole::Admin->value] : [UserRole::User->value]);

        return $token;
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
            $this->voter->vote($this->tokenFor($this->user(1)), $this->createMock(Conversation::class), ['UNKNOWN'])
        );
    }

    public function testDeniesAnonymous(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor(null), $this->createMock(Conversation::class), [ConversationVoter::VIEW])
        );
    }

    public function testDeniesNonMemberAdmin(): void
    {
        // DMs are strictly private — there is NO ROLE_ADMIN bypass. A global
        // admin who is not a participant cannot read or write a conversation.
        $this->conversationService->method('isMember')->willReturn(false);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->user(1), isAdmin: true), $this->createMock(Conversation::class), [ConversationVoter::VIEW])
        );
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->user(1), isAdmin: true), $this->createMock(Conversation::class), [ConversationVoter::WRITE])
        );
    }

    public function testGrantsMemberWhoIsAlsoAdmin(): void
    {
        // Admin-ness is irrelevant; membership is the only thing that matters.
        $this->conversationService->method('isMember')->willReturn(true);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->user(1), isAdmin: true), $this->createMock(Conversation::class), [ConversationVoter::VIEW])
        );
    }

    public function testGrantsViewWhenMember(): void
    {
        $user = $this->user(5);
        $this->conversationService->method('isMember')->willReturn(true);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($user), $this->createMock(Conversation::class), [ConversationVoter::VIEW])
        );
    }

    public function testDeniesViewWhenNotMember(): void
    {
        $user = $this->user(5);
        $this->conversationService->method('isMember')->willReturn(false);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($user), $this->createMock(Conversation::class), [ConversationVoter::VIEW])
        );
    }

    public function testGrantsWriteWhenMember(): void
    {
        $user = $this->user(5);
        $this->conversationService->method('isMember')->willReturn(true);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($user), $this->createMock(Conversation::class), [ConversationVoter::WRITE])
        );
    }
}
