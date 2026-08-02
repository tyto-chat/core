<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use App\Enum\Message\MessageKind;
use App\Enum\User\UserRole;
use App\Security\Voter\ChannelVoter;
use App\Security\Voter\MessageVoter;
use App\Service\Community\CommunityMembershipServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[AllowMockObjectsWithoutExpectations]
class MessageVoterTest extends TestCase
{
    private CommunityMembershipServiceInterface&MockObject $communityMembershipService;
    private AccessDecisionManagerInterface&MockObject $accessDecisionManager;
    private MessageVoter $voter;

    #[\Override]
    protected function setUp(): void
    {
        $this->communityMembershipService = $this->createMock(CommunityMembershipServiceInterface::class);
        $this->accessDecisionManager = $this->createMock(AccessDecisionManagerInterface::class);
        $this->voter = new MessageVoter(
            $this->communityMembershipService,
            $this->accessDecisionManager,
        );
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

    private function messageInChannel(?Channel $channel, ?User $author = null, MessageKind $kind = MessageKind::Standard): Message
    {
        $message = $this->createMock(Message::class);
        $message->method('getChannel')->willReturn($channel);
        $message->method('getConversation')->willReturn(null);
        $message->method('getCreatedBy')->willReturn($author);
        $message->method('getKind')->willReturn($kind);

        return $message;
    }

    private function messageInConversation(Conversation $conversation, ?User $author = null): Message
    {
        $message = $this->createMock(Message::class);
        $message->method('getChannel')->willReturn(null);
        $message->method('getConversation')->willReturn($conversation);
        $message->method('getCreatedBy')->willReturn($author);
        $message->method('getKind')->willReturn(MessageKind::Standard);

        return $message;
    }

    public function testAbstainsForUnsupportedAttribute(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->anonToken(), $this->createMock(Message::class), ['UNSUPPORTED'])
        );
    }

    public function testAbstainsForNonMessageSubject(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->anonToken(), new \stdClass(), [MessageVoter::VIEW])
        );
    }

    public function testDeniesAnonymousUser(): void
    {
        $message = $this->messageInChannel(new Channel());

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->anonToken(), $message, [MessageVoter::VIEW])
        );
    }

    public function testDeniesWhenMessageHasNoContainer(): void
    {
        $message = $this->messageInChannel(null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->user(1)), $message, [MessageVoter::VIEW])
        );
    }

    public function testGrantsViewWhenAccessDecisionManagerGrantsChannelView(): void
    {
        $channel = new Channel();
        $message = $this->messageInChannel($channel);

        $this->accessDecisionManager
            ->expects(self::once())
            ->method('decide')
            ->with(self::anything(), [ChannelVoter::VIEW], $channel)
            ->willReturn(true);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->user(1)), $message, [MessageVoter::VIEW])
        );
    }

    public function testDeniesViewWhenAccessDecisionManagerDeniesChannelView(): void
    {
        $channel = new Channel();
        $message = $this->messageInChannel($channel);

        $this->accessDecisionManager->method('decide')->willReturn(false);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->user(1)), $message, [MessageVoter::VIEW])
        );
    }

    public function testGrantsUpdateToAdmin(): void
    {
        $admin = $this->user(99);
        $message = $this->messageInChannel(new Channel(), author: $this->user(1));

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($admin, isAdmin: true), $message, [MessageVoter::UPDATE])
        );
    }

    public function testGrantsUpdateToMessageAuthor(): void
    {
        $author = $this->user(5);
        $message = $this->messageInChannel(new Channel(), author: $author);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($author), $message, [MessageVoter::UPDATE])
        );
    }

    public function testGrantsUpdateToChannelModerator(): void
    {
        $user = $this->user(5);
        $channel = new Channel();
        $message = $this->messageInChannel($channel, author: $this->user(1));

        $this->accessDecisionManager
            ->expects(self::once())
            ->method('decide')
            ->with(self::anything(), [ChannelVoter::MODERATE], $channel)
            ->willReturn(true);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($user), $message, [MessageVoter::UPDATE])
        );
    }

    public function testDeniesUpdateToRegularChannelMember(): void
    {
        $user = $this->user(5);
        $message = $this->messageInChannel(new Channel(), author: $this->user(1));

        $this->accessDecisionManager->method('decide')->willReturn(false);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($user), $message, [MessageVoter::UPDATE])
        );
    }

    public function testDeniesUpdateToNonMemberNonAuthor(): void
    {
        $user = $this->user(5);
        $message = $this->messageInChannel(new Channel(), author: $this->user(1));

        $this->accessDecisionManager->method('decide')->willReturn(false);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($user), $message, [MessageVoter::UPDATE])
        );
    }

    public function testDeniesViewOnDmMessageToNonParticipantAdmin(): void
    {
        // ConversationVoter (delegated to) denies the non-participant; the admin
        // role must NOT rescue them — DM contents are private to participants.
        $message = $this->messageInConversation($this->createMock(Conversation::class), author: $this->user(1));
        $this->accessDecisionManager->method('decide')->willReturn(false);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->user(99), isAdmin: true), $message, [MessageVoter::VIEW])
        );
    }

    public function testGrantsViewOnDmMessageWhenConversationVoterGrants(): void
    {
        $message = $this->messageInConversation($this->createMock(Conversation::class), author: $this->user(1));
        $this->accessDecisionManager->method('decide')->willReturn(true);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->user(5)), $message, [MessageVoter::VIEW])
        );
    }

    public function testDeniesUpdateAndDeleteOnDmMessageToNonAuthorAdmin(): void
    {
        $message = $this->messageInConversation($this->createMock(Conversation::class), author: $this->user(1));
        $admin = $this->tokenFor($this->user(99), isAdmin: true);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($admin, $message, [MessageVoter::UPDATE])
        );
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($admin, $message, [MessageVoter::DELETE])
        );
    }

    public function testGrantsUpdateAndDeleteOnDmMessageToAuthor(): void
    {
        $author = $this->user(7);
        $message = $this->messageInConversation($this->createMock(Conversation::class), author: $author);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($author), $message, [MessageVoter::UPDATE])
        );
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($author), $message, [MessageVoter::DELETE])
        );
    }
}
