<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Enum\ApiKey\ApiKeyScope;
use App\Enum\User\UserRole;
use App\Exception\ApiKey\InvalidScopeException;
use App\Service\ApiKey\ScopeIssuancePolicy;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ScopeIssuancePolicyTest extends TestCase
{
    private ScopeIssuancePolicy $policy;

    #[\Override]
    protected function setUp(): void
    {
        $this->policy = new ScopeIssuancePolicy();
    }

    private function user(string ...$roles): User
    {
        // Always include ROLE_USER, matching User::getRoles() guarantee.
        $roles[] = UserRole::User->value;
        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn(array_values(array_unique($roles)));

        return $user;
    }

    public function testAllowedScopesForRegularUserExcludesAdmin(): void
    {
        $allowed = $this->policy->allowedScopesFor($this->user());

        self::assertNotContains(ApiKeyScope::Admin->value, $allowed);
        self::assertContains(ApiKeyScope::MessagesRead->value, $allowed);
        self::assertContains(ApiKeyScope::MessagesWrite->value, $allowed);
    }

    public function testAllowedScopesForAdminIncludesAdmin(): void
    {
        $allowed = $this->policy->allowedScopesFor($this->user(UserRole::Admin->value));

        self::assertContains(ApiKeyScope::Admin->value, $allowed);
        self::assertSame(ApiKeyScope::values(), $allowed);
    }

    public function testAssertAllowedAcceptsValidScopes(): void
    {
        $this->expectNotToPerformAssertions();

        $this->policy->assertAllowed($this->user(), [
            ApiKeyScope::MessagesRead->value,
            ApiKeyScope::ConversationsRead->value,
        ]);
    }

    public function testAssertAllowedRejectsAdminScopeForNonAdmin(): void
    {
        $this->expectException(InvalidScopeException::class);

        $this->policy->assertAllowed($this->user(), [ApiKeyScope::Admin->value]);
    }

    public function testAssertAllowedAcceptsAdminScopeForAdmin(): void
    {
        $this->expectNotToPerformAssertions();

        $this->policy->assertAllowed(
            $this->user(UserRole::Admin->value),
            [ApiKeyScope::Admin->value],
        );
    }

    public function testAssertAllowedRejectsUnknownScope(): void
    {
        $this->expectException(InvalidScopeException::class);

        $this->policy->assertAllowed($this->user(), ['nonsense:scope']);
    }
}
