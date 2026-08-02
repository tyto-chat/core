<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Notification;
use App\Entity\User;
use App\Security\Voter\NotificationVoter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[AllowMockObjectsWithoutExpectations]
class NotificationVoterTest extends TestCase
{
    private NotificationVoter $voter;

    #[\Override]
    protected function setUp(): void
    {
        $this->voter = new NotificationVoter();
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

    private function user(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    private function notification(?User $recipient): Notification
    {
        $notification = $this->createMock(Notification::class);
        $notification->method('getRecipient')->willReturn($recipient);

        return $notification;
    }

    public function testAbstainsForUnsupportedAttribute(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->anonToken(), new Notification(), ['UNSUPPORTED'])
        );
    }

    public function testAbstainsForNonNotificationSubject(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->anonToken(), new \stdClass(), [NotificationVoter::VIEW])
        );
    }

    #[DataProvider('provideBothAttributes')]
    public function testDeniesAnonymousUser(string $attribute): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->anonToken(), $this->notification(null), [$attribute])
        );
    }

    /** @return iterable<string, array{string}> */
    public static function provideBothAttributes(): iterable
    {
        yield 'VIEW' => [NotificationVoter::VIEW];
        yield 'UPDATE' => [NotificationVoter::UPDATE];
    }

    #[DataProvider('provideBothAttributes')]
    public function testGrantsWhenUserIsRecipient(string $attribute): void
    {
        $user = $this->user(7);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($user), $this->notification($user), [$attribute])
        );
    }

    #[DataProvider('provideBothAttributes')]
    public function testDeniesWhenUserIsNotRecipient(string $attribute): void
    {
        $user = $this->user(7);
        $otherUser = $this->user(99);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($user), $this->notification($otherUser), [$attribute])
        );
    }

    #[DataProvider('provideBothAttributes')]
    public function testDeniesWhenNotificationHasNoRecipient(string $attribute): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->user(1)), $this->notification(null), [$attribute])
        );
    }
}
