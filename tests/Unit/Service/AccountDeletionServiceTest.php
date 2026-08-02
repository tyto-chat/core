<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Profile;
use App\Entity\User;
use App\Exception\User\AccountAlreadyPendingDeletionException;
use App\Exception\User\AccountNotPendingDeletionException;
use App\Repository\RecoveryCodeRepository;
use App\Repository\UserRepository;
use App\Security\SecurityContext;
use App\Service\ApiKey\ApiKeyServiceInterface;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Gdpr\AccountDeletionService;
use App\Service\Gdpr\AccountDeletionServiceInterface;
use App\Service\MediaObject\MediaObjectServiceInterface;
use App\Service\Notification\PushSubscriptionServiceInterface;
use App\Service\Security\SessionRevokerInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;

#[AllowMockObjectsWithoutExpectations]
class AccountDeletionServiceTest extends TestCase
{
    private UserRepository&MockObject $userRepository;
    private ApiKeyServiceInterface&MockObject $apiKeyService;
    private PushSubscriptionServiceInterface&MockObject $pushSubscriptionService;
    private MediaObjectServiceInterface&MockObject $mediaObjectService;
    private RecoveryCodeRepository&MockObject $recoveryCodeRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private Security&MockObject $security;
    private SessionRevokerInterface&MockObject $sessionRevoker;
    private AccountDeletionService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->apiKeyService = $this->createMock(ApiKeyServiceInterface::class);
        $this->pushSubscriptionService = $this->createMock(PushSubscriptionServiceInterface::class);
        $this->mediaObjectService = $this->createMock(MediaObjectServiceInterface::class);
        $this->recoveryCodeRepository = $this->createMock(RecoveryCodeRepository::class);
        $this->sessionRevoker = $this->createMock(SessionRevokerInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->security = $this->createMock(Security::class);

        $this->service = new AccountDeletionService(
            new SecurityContext($this->security, $this->createMock(CommunityMembershipServiceInterface::class)),
            $this->userRepository,
            $this->apiKeyService,
            $this->pushSubscriptionService,
            $this->mediaObjectService,
            $this->recoveryCodeRepository,
            $this->sessionRevoker,
        );
        $this->service->setEntityManager($this->entityManager);
        $this->service->setLogger(new NullLogger());
    }

    private function makeUser(bool $pending = false): User
    {
        $user = new User();
        $user->setEmail('alice@example.com');
        $user->setPassword('hash');
        if ($pending) {
            $user->setDeletionRequestedAt(new \DateTimeImmutable('-1 day'));
        }
        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')->willReturn(true);

        return $user;
    }

    public function testRequestDeletionSetsTimestampAndRevokesArtifacts(): void
    {
        $user = $this->makeUser();

        $this->apiKeyService->expects(self::once())->method('revokeAllFor')->with($user);
        $this->pushSubscriptionService->expects(self::once())->method('removeAllFor')->with($user);
        $this->entityManager->expects(self::once())->method('flush');

        $result = $this->service->requestDeletion();

        self::assertSame($user, $result);
        self::assertTrue($user->isPendingDeletion());
    }

    public function testRequestDeletionRejectsWhenAlreadyPending(): void
    {
        $this->makeUser(pending: true);

        $this->expectException(AccountAlreadyPendingDeletionException::class);
        $this->entityManager->expects(self::never())->method('flush');

        $this->service->requestDeletion();
    }

    public function testCancelDeletionClearsTimestamp(): void
    {
        $user = $this->makeUser(pending: true);
        $this->entityManager->expects(self::once())->method('flush');

        $this->service->cancelDeletion();

        self::assertFalse($user->isPendingDeletion());
    }

    public function testCancelDeletionRejectsWhenNotPending(): void
    {
        $this->makeUser();

        $this->expectException(AccountNotPendingDeletionException::class);
        $this->entityManager->expects(self::never())->method('flush');

        $this->service->cancelDeletion();
    }

    public function testFindPurgeDateNullWhenNotPending(): void
    {
        $this->makeUser();
        self::assertNull($this->service->findPurgeDateForCurrentUser());
    }

    public function testFindPurgeDateIsRequestPlusGrace(): void
    {
        $user = $this->makeUser(pending: true);
        $expected = $user->getDeletionRequestedAt()?->modify(
            '+'.AccountDeletionServiceInterface::GRACE_PERIOD_DAYS.' days',
        );

        self::assertEquals($expected, $this->service->findPurgeDateForCurrentUser());
    }

    public function testPurgeExpiredAnonymisesInPlace(): void
    {
        $user = new User();
        $user->setEmail('alice@example.com');
        $user->setPassword('original-hash');
        $user->setDeletionRequestedAt(new \DateTimeImmutable('-8 days'));
        $user->setTwoFactorSecret('encrypted-secret');
        $user->setTotpEnabledAt(new \DateTimeImmutable('-30 days'));
        $userId = (new \ReflectionClass(User::class))->getProperty('id');
        $userId->setValue($user, 99);

        $profile = $this->createMock(Profile::class);
        $profile->expects(self::once())->method('setName')->with('Deleted user');
        $profile->expects(self::once())->method('setAvatar')->with(null);
        (new \ReflectionClass(User::class))->getProperty('profile')->setValue($user, $profile);

        $this->userRepository->method('findExpiredForPurge')->willReturn([$user]);
        $this->entityManager->expects(self::once())->method('flush');
        $this->recoveryCodeRepository->expects(self::once())->method('deleteAllForUser')->with($user);

        $count = $this->service->purgeExpired();

        self::assertSame(1, $count);
        self::assertSame('deleted-99@invalid.local', $user->getEmail());
        self::assertNotSame('original-hash', $user->getPassword());
        self::assertTrue($user->isBot());
        self::assertSame([\App\Enum\User\UserRole::User->value], $user->getRoles()); // ROLE_USER kept by getter
        self::assertNull($user->getTwoFactorSecret());
        self::assertNull($user->getTotpEnabledAt());
    }

    public function testPurgeExpiredDeletesAvatarMediaObject(): void
    {
        $user = new User();
        $user->setEmail('alice@example.com');
        $user->setPassword('hash');
        $user->setDeletionRequestedAt(new \DateTimeImmutable('-8 days'));

        $avatar = new \App\Entity\MediaObject();
        $profile = $this->createMock(Profile::class);
        $profile->method('getAvatar')->willReturn($avatar);
        $profile->expects(self::once())->method('setAvatar')->with(null);
        (new \ReflectionClass(User::class))->getProperty('profile')->setValue($user, $profile);

        $this->userRepository->method('findExpiredForPurge')->willReturn([$user]);
        $this->mediaObjectService->expects(self::once())->method('delete')->with($avatar);

        $this->service->purgeExpired();
    }

    public function testPurgeExpiredNoOpWhenEmpty(): void
    {
        $this->userRepository->method('findExpiredForPurge')->willReturn([]);
        $this->entityManager->expects(self::never())->method('flush');

        self::assertSame(0, $this->service->purgeExpired());
    }
}
