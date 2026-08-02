<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\RecoveryCode;
use App\Entity\User;
use App\Repository\RecoveryCodeRepository;
use App\Service\Gdpr\AccountDeletionServiceInterface;
use App\Tests\Factory\ApiKeyFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class AccountDeletionTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testStatusNotPendingReturnsFlagFalse(): void
    {
        $user = UserFactory::createOne();

        $response = $this->plainJsonClient($user)->request('GET', '/api/v1/me/account-deletion');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['pending' => false], $response->toArray());
    }

    public function testStatusPendingReturnsPurgeDate(): void
    {
        $user = UserFactory::createOne();
        $this->markPendingDeletion($user, new \DateTimeImmutable('-1 day'));

        $response = $this->plainJsonClient($user)->request('GET', '/api/v1/me/account-deletion');

        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertTrue($body['pending']);
        self::assertArrayHasKey('purgeAt', $body);
    }

    public function testRequestSchedulesDeletion(): void
    {
        $user = UserFactory::createOne();

        $response = $this->plainJsonClient($user)->request('POST', '/api/v1/me/account-deletion');

        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertTrue($body['pending']);
        self::assertArrayHasKey('purgeAt', $body);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($user->getId());
        self::assertNotNull($reloaded?->getDeletionRequestedAt());
    }

    public function testRequestRevokesAllApiKeys(): void
    {
        $user = UserFactory::createOne();
        ApiKeyFactory::createOne(['user' => $user]);
        $issued = ApiKeyFactory::createWithToken($user);

        $this->plainJsonClient($user)->request('POST', '/api/v1/me/account-deletion');
        self::assertResponseStatusCodeSame(200);

        // Token now rejected — key is revoked.
        $client = static::createClient(defaultOptions: [
            'headers' => ['Authorization' => 'Bearer '.$issued['plainToken'], 'Accept' => 'application/ld+json'],
        ]);
        $client->request('GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testRequestRejectsDoubleScheduling(): void
    {
        $user = UserFactory::createOne();
        $this->markPendingDeletion($user, new \DateTimeImmutable('-1 hour'));

        $this->plainJsonClient($user)->request('POST', '/api/v1/me/account-deletion');

        self::assertResponseStatusCodeSame(409);
    }

    public function testCancelClearsScheduledDeletion(): void
    {
        $user = UserFactory::createOne();
        $this->markPendingDeletion($user, new \DateTimeImmutable('-1 hour'));

        $this->plainJsonClient($user)->request('DELETE', '/api/v1/me/account-deletion');

        self::assertResponseStatusCodeSame(204);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($user->getId());
        self::assertNull($reloaded?->getDeletionRequestedAt());
    }

    public function testCancelRejectsWhenNotPending(): void
    {
        $user = UserFactory::createOne();

        $this->plainJsonClient($user)->request('DELETE', '/api/v1/me/account-deletion');

        self::assertResponseStatusCodeSame(409);
    }

    public function testPurgeExpiredAnonymisesGraceElapsedAccount(): void
    {
        $user = UserFactory::createOne();
        $userId = $user->getId();
        $this->markPendingDeletion($user, new \DateTimeImmutable('-10 days'));

        $service = static::getContainer()->get(AccountDeletionServiceInterface::class);
        \assert($service instanceof AccountDeletionServiceInterface);
        $count = $service->purgeExpired();

        self::assertSame(1, $count);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($userId);
        self::assertNotNull($reloaded);
        self::assertSame(sprintf('deleted-%d@invalid.local', $userId), $reloaded->getEmail());
        self::assertTrue($reloaded->isBot());
    }

    public function testPurgeExpiredWipesTwoFactorSecret(): void
    {
        $user = UserFactory::new()->withTwoFactor()->create();
        $userId = $user->getId();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $recoveryCode = new RecoveryCode();
        $recoveryCode->setUser($user);
        $recoveryCode->setCodeHash(hash('sha256', 'unused-code'));
        $em->persist($recoveryCode);
        $em->flush();

        $this->markPendingDeletion($user, new \DateTimeImmutable('-10 days'));

        $service = static::getContainer()->get(AccountDeletionServiceInterface::class);
        \assert($service instanceof AccountDeletionServiceInterface);
        self::assertSame(1, $service->purgeExpired());

        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($userId);
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getTwoFactorSecret());
        self::assertNull($reloaded->getTotpEnabledAt());

        $recoveryCodeRepository = static::getContainer()->get(RecoveryCodeRepository::class);
        \assert($recoveryCodeRepository instanceof RecoveryCodeRepository);
        self::assertSame(0, $recoveryCodeRepository->count(['user' => $reloaded]));
    }

    public function testPurgeExpiredSkipsWithinGrace(): void
    {
        $user = UserFactory::createOne();
        $this->markPendingDeletion($user, new \DateTimeImmutable('-1 day'));

        $service = static::getContainer()->get(AccountDeletionServiceInterface::class);
        \assert($service instanceof AccountDeletionServiceInterface);

        self::assertSame(0, $service->purgeExpired());
    }

    public function testPendingDeletionUserCanReadMe(): void
    {
        $user = UserFactory::createOne();
        $this->markPendingDeletion($user, new \DateTimeImmutable('-1 hour'));

        $this->jsonClient($user)->request('GET', '/api/v1/me');

        self::assertResponseStatusCodeSame(200);
    }

    public function testPendingDeletionUserCanCancelDeletion(): void
    {
        $user = UserFactory::createOne();
        $this->markPendingDeletion($user, new \DateTimeImmutable('-1 hour'));

        $this->plainJsonClient($user)->request('DELETE', '/api/v1/me/account-deletion');

        self::assertResponseStatusCodeSame(204);
    }

    public function testPendingDeletionUserIsLockedOutOfOtherEndpoints(): void
    {
        $user = UserFactory::createOne();
        $this->markPendingDeletion($user, new \DateTimeImmutable('-1 hour'));

        // /api/communities is a public list — still gated for deletion-pending
        // users because they shouldn't be browsing while scheduled.
        $this->jsonClient($user)->request('GET', '/api/v1/communities');

        self::assertResponseStatusCodeSame(423);
    }

    public function testPendingDeletionUserCanFetchRealtimeToken(): void
    {
        $user = UserFactory::createOne();
        $this->markPendingDeletion($user, new \DateTimeImmutable('-1 hour'));

        // AuthContext.doMercureFetch fires unconditionally on login; gate must
        // not 423 it or the network tab fills with red on every re-login.
        $this->plainJsonClient($user)->request('GET', '/api/v1/realtime/token');

        self::assertResponseStatusCodeSame(200);
    }

    public function testHealthyUserNotAffectedByListener(): void
    {
        $user = UserFactory::createOne();

        $this->jsonClient($user)->request('GET', '/api/v1/communities');

        self::assertResponseIsSuccessful();
    }

    private function markPendingDeletion(User $user, \DateTimeImmutable $at): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $managed = $em->getRepository(User::class)->find($user->getId());
        $managed?->setDeletionRequestedAt($at);
        $em->flush();
    }
}
