<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\ApiKey;
use App\Enum\ApiKey\ApiKeyScope;
use App\Tests\Factory\ApiKeyFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ApiKeyAuthTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testValidApiKeyAuthenticates(): void
    {
        $user = UserFactory::createOne();
        $issued = ApiKeyFactory::createWithToken($user);

        $response = $this->withToken($issued['plainToken'])->request('GET', '/api/v1/me');

        self::assertResponseStatusCodeSame(200);
        self::assertSame($user->getEmail(), $response->toArray()['email'] ?? null);
    }

    public function testRevokedApiKeyIsRejected(): void
    {
        $user = UserFactory::createOne();
        $issued = ApiKeyFactory::createWithToken($user, ['revokedAt' => new \DateTimeImmutable('-1 hour')]);

        $this->assertUnauthorizedWithToken($issued['plainToken']);
    }

    public function testExpiredApiKeyIsRejected(): void
    {
        $user = UserFactory::createOne();
        $issued = ApiKeyFactory::createWithToken($user, ['expiresAt' => new \DateTimeImmutable('-1 hour')]);

        $this->assertUnauthorizedWithToken($issued['plainToken']);
    }

    public function testUnknownPatTokenIsRejected(): void
    {
        UserFactory::createOne();

        $this->assertUnauthorizedWithToken('pat_unknown_garbage_xxxxxxxxxxxxxxxxxxxxxxxx');
    }

    public function testInvalidJwtTokenStillReturns401(): void
    {
        UserFactory::createOne();

        // Verifies JWT path is unaffected by adding our authenticator alongside.
        $this->assertUnauthorizedWithToken('not-a-real-jwt');
    }

    public function testTouchLastUsedUpdatesOnSuccessfulAuth(): void
    {
        $user = UserFactory::createOne();
        $issued = ApiKeyFactory::createWithToken($user);
        self::assertNull($issued['key']->getLastUsedAt());
        $keyId = $issued['key']->getId();

        $this->withToken($issued['plainToken'])->request('GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(200);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
        $reloaded = $em->getRepository(ApiKey::class)->find($keyId);
        self::assertNotNull($reloaded?->getLastUsedAt());
    }

    public function testBotUserCanAuthenticateWithApiKey(): void
    {
        $user = UserFactory::new()->bot()->create();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ProfileRead->value]]);

        $response = $this->withToken($issued['plainToken'])->request('GET', '/api/v1/me');

        self::assertResponseStatusCodeSame(200);
        self::assertSame($user->getEmail(), $response->toArray()['email'] ?? null);
    }

    public function testKeyForServerBannedUserIsRejected(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('apikey-sb-test')->create();
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $issued = ApiKeyFactory::createWithToken($target, ['scopes' => [ApiKeyScope::ProfileRead->value]]);

        $this->jsonClient($admin)->request('POST', '/api/v1/communities/apikey-sb-test/moderation', ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'server_ban',
            'reason' => 'testing PAT rejection',
        ]]);
        self::assertResponseStatusCodeSame(201);

        $this->assertUnauthorizedWithToken($issued['plainToken']);
    }

    private function withToken(string $token): \ApiPlatform\Symfony\Bundle\Test\Client
    {
        return static::createClient(defaultOptions: [
            'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/ld+json'],
        ]);
    }

    private function assertUnauthorizedWithToken(string $token): void
    {
        $this->withToken($token)->request('GET', '/api/v1/me');

        self::assertResponseStatusCodeSame(401);
    }
}
