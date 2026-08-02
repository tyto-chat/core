<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Enum\User\UserRole;
use App\Service\UserContextService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class UserContextServiceTest extends TestCase
{
    private TokenStorage $tokenStorage;
    private UserContextService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->tokenStorage = new TokenStorage();
        $this->service = new UserContextService($this->tokenStorage);
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles = []): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setRoles([] === $roles ? [UserRole::User->value] : $roles);

        return $user;
    }

    public function testReturnsClosureResult(): void
    {
        $result = $this->service->runAs($this->user('a@b.test'), static fn (): int => 42);

        self::assertSame(42, $result);
    }

    public function testSwapsUserDuringClosure(): void
    {
        $target = $this->user('target@test');
        $seen = null;

        $this->service->runAs($target, function () use (&$seen): void {
            $seen = $this->tokenStorage->getToken()?->getUser();
        });

        self::assertSame($target, $seen);
    }

    public function testTokenInsideClosureCarriesUserRoles(): void
    {
        $admin = $this->user('admin@test', [UserRole::Admin->value]);
        $rolesSeen = null;

        $this->service->runAs($admin, function () use (&$rolesSeen): void {
            $rolesSeen = $this->tokenStorage->getToken()?->getRoleNames();
        });

        self::assertContains(UserRole::Admin->value, $rolesSeen ?? []);
    }

    public function testRestoresPreviousTokenAfterClosure(): void
    {
        $original = new UsernamePasswordToken($this->user('original@test'), 'main', [UserRole::User->value]);
        $this->tokenStorage->setToken($original);

        $this->service->runAs($this->user('bot@test'), static fn () => null);

        self::assertSame($original, $this->tokenStorage->getToken());
    }

    public function testRestoresNullTokenAfterClosureWhenStartingEmpty(): void
    {
        self::assertNull($this->tokenStorage->getToken());

        $this->service->runAs($this->user('bot@test'), static fn () => null);

        self::assertNull($this->tokenStorage->getToken());
    }

    public function testRestoresPreviousTokenWhenClosureThrows(): void
    {
        $original = new UsernamePasswordToken($this->user('original@test'), 'main', [UserRole::User->value]);
        $this->tokenStorage->setToken($original);

        try {
            $this->service->runAs($this->user('bot@test'), static function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame($original, $this->tokenStorage->getToken());
    }

    public function testSupportsNesting(): void
    {
        $original = new UsernamePasswordToken($this->user('original@test'), 'main', [UserRole::User->value]);
        $this->tokenStorage->setToken($original);

        $outer = $this->user('outer@test');
        $inner = $this->user('inner@test');

        /** @var array{outer: ?TokenInterface, inner: ?TokenInterface, outerAfter: ?TokenInterface} $observed */
        $observed = ['outer' => null, 'inner' => null, 'outerAfter' => null];

        $this->service->runAs($outer, function () use ($inner, &$observed): void {
            $observed['outer'] = $this->tokenStorage->getToken();

            $this->service->runAs($inner, function () use (&$observed): void {
                $observed['inner'] = $this->tokenStorage->getToken();
            });

            $observed['outerAfter'] = $this->tokenStorage->getToken();
        });

        self::assertSame($outer, $observed['outer']?->getUser());
        self::assertSame($inner, $observed['inner']?->getUser());
        self::assertSame($outer, $observed['outerAfter']?->getUser(), 'inner runAs must restore outer token');
        self::assertSame($original, $this->tokenStorage->getToken(), 'outer runAs must restore original token');
    }
}
