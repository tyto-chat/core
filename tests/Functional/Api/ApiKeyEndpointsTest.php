<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\ApiKey\ApiKeyScope;
use App\Repository\ApiKeyRepository;
use App\Tests\Factory\ApiKeyFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ApiKeyEndpointsTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testListReturnsOnlyCurrentUsersKeys(): void
    {
        $alice = UserFactory::createOne();
        $bob = UserFactory::createOne();
        ApiKeyFactory::createOne(['user' => $alice, 'name' => 'alice key 1']);
        ApiKeyFactory::createOne(['user' => $alice, 'name' => 'alice key 2']);
        ApiKeyFactory::createOne(['user' => $bob, 'name' => 'bob key']);

        $response = $this->jsonClient($alice)->request('GET', '/api/v1/me/api-keys');

        self::assertResponseStatusCodeSame(200);
        $members = $response->toArray()['hydra:member'] ?? [];
        self::assertCount(2, $members);
        $names = array_column($members, 'name');
        self::assertContains('alice key 1', $names);
        self::assertContains('alice key 2', $names);
        // Token hash never surfaces.
        self::assertArrayNotHasKey('tokenHash', $members[0]);
    }

    public function testListRequiresAuthentication(): void
    {
        static::createClient()->request('GET', '/api/v1/me/api-keys');

        self::assertResponseStatusCodeSame(401);
    }

    public function testIssueReturnsPlainTokenOnce(): void
    {
        $user = UserFactory::createOne();

        $response = $this->jsonClient($user)->request('POST', '/api/v1/me/api-keys', [
            'json' => [
                'name' => 'CI bot',
                'scopes' => [ApiKeyScope::MessagesRead->value, ApiKeyScope::MessagesWrite->value],
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $body = $response->toArray();
        self::assertSame('CI bot', $body['name']);
        self::assertSame([
            ApiKeyScope::MessagesRead->value,
            ApiKeyScope::MessagesWrite->value,
        ], $body['scopes']);
        self::assertStringStartsWith('pat_', $body['plainToken']);
        self::assertSame(47, strlen($body['plainToken']));

        // Subsequent list does NOT include the plaintext.
        $list = $this->jsonClient($user)->request('GET', '/api/v1/me/api-keys')->toArray();
        $first = $list['hydra:member'][0];
        self::assertArrayNotHasKey('plainToken', $first);
        self::assertArrayNotHasKey('tokenHash', $first);
    }

    public function testIssueRejectsAdminScopeForNonAdmin(): void
    {
        $user = UserFactory::createOne();

        $this->jsonClient($user)->request('POST', '/api/v1/me/api-keys', [
            'json' => ['name' => 'bad', 'scopes' => [ApiKeyScope::Admin->value]],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testIssueRejectsEmptyScopes(): void
    {
        $user = UserFactory::createOne();

        $this->jsonClient($user)->request('POST', '/api/v1/me/api-keys', [
            'json' => ['name' => 'empty', 'scopes' => []],
        ]);

        // Validator catches this before service — 422 either way.
        self::assertResponseStatusCodeSame(422);
    }

    public function testIssueRejectsUnknownScope(): void
    {
        $user = UserFactory::createOne();

        $this->jsonClient($user)->request('POST', '/api/v1/me/api-keys', [
            'json' => ['name' => 'unknown', 'scopes' => ['nonsense:scope']],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testRevokeSetsRevokedAt(): void
    {
        $user = UserFactory::createOne();
        $key = ApiKeyFactory::createOne(['user' => $user]);
        $keyId = $key->getId();

        $this->jsonClient($user)->request('DELETE', '/api/v1/me/api-keys/'.$keyId);

        self::assertResponseStatusCodeSame(204);

        $em = static::getContainer()->get(ApiKeyRepository::class);
        $reloaded = $em->find($keyId);
        self::assertNotNull($reloaded?->getRevokedAt());
    }

    public function testRevokeDeniedForOtherUsersKey(): void
    {
        $alice = UserFactory::createOne();
        $bob = UserFactory::createOne();
        $aliceKey = ApiKeyFactory::createOne(['user' => $alice]);

        $response = $this->jsonClient($bob)->request('DELETE', '/api/v1/me/api-keys/'.$aliceKey->getId());

        // ApiKeyVoter::MANAGE denies — AP returns 403 (or 404 if security
        // hides existence). Either is acceptable.
        self::assertContains($response->getStatusCode(), [403, 404]);

        $em = static::getContainer()->get(ApiKeyRepository::class);
        $reloaded = $em->find($aliceKey->getId());
        self::assertNull($reloaded?->getRevokedAt(), 'Alice\'s key must not be revoked by Bob.');
    }

    public function testRevokeAllowedForAdmin(): void
    {
        $alice = UserFactory::createOne();
        $admin = UserFactory::createOne(['roles' => ['ROLE_ADMIN', 'ROLE_USER']]);
        $aliceKey = ApiKeyFactory::createOne(['user' => $alice]);

        $this->jsonClient($admin)->request('DELETE', '/api/v1/me/api-keys/'.$aliceKey->getId());

        self::assertResponseStatusCodeSame(204);

        $em = static::getContainer()->get(ApiKeyRepository::class);
        $reloaded = $em->find($aliceKey->getId());
        self::assertNotNull($reloaded?->getRevokedAt());
    }

    public function testPatAuthCannotListKeys(): void
    {
        $user = UserFactory::createOne();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ProfileRead->value]]);

        $client = static::createClient(defaultOptions: [
            'headers' => [
                'Authorization' => 'Bearer '.$issued['plainToken'],
                'Accept' => 'application/ld+json',
            ],
        ]);
        $client->request('GET', '/api/v1/me/api-keys');

        // Untagged endpoint + PAT auth = 403 (safe-default in ApiKeyScopeListener).
        // Prevents privilege escalation: PAT cannot mint new keys.
        self::assertResponseStatusCodeSame(403);
    }
}
