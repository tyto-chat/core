<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Enum\ApiKey\ApiKeyScope;
use App\Enum\User\UserRole;
use App\Exception\ApiKey\EmptyScopesException;
use App\Exception\ApiKey\InvalidScopeException;
use App\Repository\ApiKeyRepository;
use App\Security\SecurityContext;
use App\Service\ApiKey\ApiKeyService;
use App\Service\ApiKey\ScopeIssuancePolicy;
use App\Service\Community\CommunityMembershipServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;

#[AllowMockObjectsWithoutExpectations]
class ApiKeyServiceTest extends TestCase
{
    private ApiKeyRepository&MockObject $repository;
    private EntityManagerInterface&MockObject $entityManager;
    private Security&MockObject $security;
    private ApiKeyService $service;
    private User $user;

    #[\Override]
    protected function setUp(): void
    {
        $this->repository = $this->createMock(ApiKeyRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->security = $this->createMock(Security::class);

        $this->user = $this->createMock(User::class);
        $this->user->method('getRoles')->willReturn([UserRole::User->value]);
        $this->user->method('getId')->willReturn(42);
        $this->security->method('getUser')->willReturn($this->user);
        $this->security->method('isGranted')->willReturn(true);

        $this->service = new ApiKeyService(new SecurityContext($this->security, $this->createMock(CommunityMembershipServiceInterface::class)), $this->repository, new ScopeIssuancePolicy());
        $this->service->setEntityManager($this->entityManager);
        $this->service->setLogger(new NullLogger());
    }

    public function testIssueReturnsPlaintextAndPersistsHashedKey(): void
    {
        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $issued = $this->service->issue('CI bot', [ApiKeyScope::MessagesRead->value]);

        self::assertStringStartsWith('pat_', $issued->plainToken);
        self::assertSame(47, strlen($issued->plainToken)); // 'pat_' + 43 base64url chars
        self::assertSame(hash('sha256', $issued->plainToken), $issued->key->getTokenHash());
        self::assertSame('CI bot', $issued->key->getName());
        self::assertSame([ApiKeyScope::MessagesRead->value], $issued->key->getScopes());
        self::assertSame($this->user, $issued->key->getUser());
        self::assertSame(substr($issued->plainToken, 0, 12), $issued->key->getPrefix());
    }

    public function testIssueRejectsEmptyScopes(): void
    {
        $this->expectException(EmptyScopesException::class);
        $this->entityManager->expects(self::never())->method('persist');

        $this->service->issue('CI bot', []);
    }

    public function testIssueRejectsAdminScopeForNonAdmin(): void
    {
        $this->expectException(InvalidScopeException::class);
        $this->entityManager->expects(self::never())->method('persist');

        $this->service->issue('CI bot', [ApiKeyScope::Admin->value]);
    }

    public function testIssueDeduplicatesScopes(): void
    {
        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $issued = $this->service->issue('CI', [
            ApiKeyScope::MessagesRead->value,
            ApiKeyScope::MessagesRead->value,
            ApiKeyScope::ProfileRead->value,
        ]);

        self::assertSame([
            ApiKeyScope::MessagesRead->value,
            ApiKeyScope::ProfileRead->value,
        ], $issued->key->getScopes());
    }

    public function testIssuePersistsExpiry(): void
    {
        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $expires = new \DateTimeImmutable('+30 days');
        $issued = $this->service->issue('CI', [ApiKeyScope::MessagesRead->value], $expires);

        self::assertSame($expires, $issued->key->getExpiresAt());
    }

    public function testAdminKeyGetsDefaultExpiryWhenOmitted(): void
    {
        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $issued = $this->adminService()->issue('admin bot', [ApiKeyScope::Admin->value]);

        $expiry = $issued->key->getExpiresAt();
        self::assertNotNull($expiry);
        self::assertGreaterThan(new \DateTimeImmutable('+89 days'), $expiry);
        self::assertLessThan(new \DateTimeImmutable('+91 days'), $expiry);
    }

    public function testAdminKeyExpiryClampedToMax(): void
    {
        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $issued = $this->adminService()->issue('admin bot', [ApiKeyScope::Admin->value], new \DateTimeImmutable('+365 days'));

        $expiry = $issued->key->getExpiresAt();
        self::assertNotNull($expiry);
        self::assertLessThan(new \DateTimeImmutable('+91 days'), $expiry);
    }

    public function testAdminKeyExpiryKeptWhenWithinMax(): void
    {
        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $within = new \DateTimeImmutable('+10 days');
        $issued = $this->adminService()->issue('admin bot', [ApiKeyScope::Admin->value], $within);

        self::assertSame($within, $issued->key->getExpiresAt());
    }

    private function adminService(): ApiKeyService
    {
        $admin = $this->createMock(User::class);
        $admin->method('getRoles')->willReturn([UserRole::Admin->value, UserRole::User->value]);
        $admin->method('getId')->willReturn(7);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($admin);
        $security->method('isGranted')->willReturn(true);

        $service = new ApiKeyService(new SecurityContext($security, $this->createMock(CommunityMembershipServiceInterface::class)), $this->repository, new ScopeIssuancePolicy());
        $service->setEntityManager($this->entityManager);
        $service->setLogger(new NullLogger());

        return $service;
    }

    public function testRevokeSetsRevokedAt(): void
    {
        $key = $this->makeKey();
        $this->entityManager->expects(self::once())->method('flush');

        $this->service->revoke($key);

        self::assertTrue($key->isRevoked());
    }

    public function testRevokeIsIdempotent(): void
    {
        $key = $this->makeKey();
        $key->setRevokedAt(new \DateTimeImmutable('-1 day'));

        $this->entityManager->expects(self::never())->method('flush');

        $this->service->revoke($key);
    }

    public function testFindByPlainTokenReturnsNullForWrongPrefix(): void
    {
        $this->repository->expects(self::never())->method('findOneByTokenHash');

        self::assertNull($this->service->findByPlainToken('jwt_abc'));
    }

    public function testFindByPlainTokenHashesAndLooksUp(): void
    {
        $token = 'pat_abcdef';
        $key = $this->makeKey();
        $this->repository->expects(self::once())
            ->method('findOneByTokenHash')
            ->with(hash('sha256', $token))
            ->willReturn($key);

        self::assertSame($key, $this->service->findByPlainToken($token));
    }

    public function testTouchLastUsedWritesOnFirstUse(): void
    {
        $key = $this->makeKey();
        self::assertNull($key->getLastUsedAt());
        $this->entityManager->expects(self::once())->method('flush');

        $this->service->touchLastUsed($key);

        self::assertNotNull($key->getLastUsedAt());
    }

    public function testTouchLastUsedSkipsWithinThrottleWindow(): void
    {
        $key = $this->makeKey();
        $key->setLastUsedAt(new \DateTimeImmutable('-5 seconds'));
        $this->entityManager->expects(self::never())->method('flush');

        $this->service->touchLastUsed($key);
    }

    public function testTouchLastUsedUpdatesAfterThrottleWindow(): void
    {
        $key = $this->makeKey();
        $earlier = new \DateTimeImmutable('-2 minutes');
        $key->setLastUsedAt($earlier);
        $this->entityManager->expects(self::once())->method('flush');

        $this->service->touchLastUsed($key);

        self::assertNotSame($earlier, $key->getLastUsedAt());
    }

    private function makeKey(): ApiKey
    {
        $key = new ApiKey();
        $key->setUser($this->user)
            ->setName('test')
            ->setPrefix('pat_xxxxxx')
            ->setTokenHash(str_repeat('a', 64))
            ->setScopes([ApiKeyScope::MessagesRead->value]);

        return $key;
    }
}
