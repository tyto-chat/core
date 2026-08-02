<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Enum\User\UserRole;
use App\Security\Voter\AdminPanelVoter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[AllowMockObjectsWithoutExpectations]
class AdminPanelVoterTest extends TestCase
{
    /** @return iterable<array{string}> */
    public static function attributeProvider(): iterable
    {
        yield [AdminPanelVoter::VIEW];
        yield [AdminPanelVoter::USER_MANAGE];
        yield [AdminPanelVoter::COMMUNITY_MANAGE];
        yield [AdminPanelVoter::SERVER_CONFIG];
    }

    private function tokenWithRoles(string ...$roles): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getRoleNames')->willReturn($roles);

        return $token;
    }

    #[DataProvider('attributeProvider')]
    public function testGrantsWhenTokenHasAdminRole(string $attribute): void
    {
        $voter = new AdminPanelVoter();

        $result = $voter->vote($this->tokenWithRoles(UserRole::Admin->value), null, [$attribute]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[DataProvider('attributeProvider')]
    public function testDeniesWhenTokenIsAnon(string $attribute): void
    {
        $voter = new AdminPanelVoter();

        $result = $voter->vote($this->tokenWithRoles(), null, [$attribute]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[DataProvider('attributeProvider')]
    public function testDeniesWhenTokenIsNonAdminUser(string $attribute): void
    {
        $voter = new AdminPanelVoter();

        $result = $voter->vote($this->tokenWithRoles(UserRole::User->value), null, [$attribute]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testAbstainsOnUnsupportedAttribute(): void
    {
        $voter = new AdminPanelVoter();

        $result = $voter->vote($this->tokenWithRoles(UserRole::Admin->value), null, ['SOMETHING_ELSE']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }
}
