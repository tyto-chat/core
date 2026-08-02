<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Enum\ApiKey\ApiKeyScope;
use App\Tests\Factory\ApiKeyFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ApiKeyScopeTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testKeyWithProfileReadCanGetMe(): void
    {
        $user = UserFactory::createOne();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ProfileRead->value]]);

        $this->withToken($issued['plainToken'])->request('GET', '/api/v1/me');

        self::assertResponseStatusCodeSame(200);
    }

    public function testKeyWithoutProfileReadIsRejected(): void
    {
        $user = UserFactory::createOne();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesRead->value]]);

        $response = $this->withToken($issued['plainToken'])->request('GET', '/api/v1/me');

        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString('profile:read', $headers['www-authenticate'][0] ?? '');
    }

    public function testAdminScopedEndpointRequiresAdminScope(): void
    {
        $admin = UserFactory::createOne(['roles' => ['ROLE_ADMIN', 'ROLE_USER']]);
        $issuedReadOnly = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::ProfileRead->value]]);

        $this->withToken($issuedReadOnly['plainToken'])->request('GET', '/api/v1/users');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminScopedEndpointAcceptsAdminScope(): void
    {
        $admin = UserFactory::createOne(['roles' => ['ROLE_ADMIN', 'ROLE_USER']]);
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::Admin->value]]);

        $this->withToken($issued['plainToken'])->request('GET', '/api/v1/users');

        self::assertResponseStatusCodeSame(200);
    }

    public function testAdminScopedPatCanReachAdminShell(): void
    {
        // The admin shell is now tagged `scope: admin` on its API Platform
        // ops (Phase 3.5 PAT scope tagging): an admin-scoped PAT is exactly
        // as dangerous as an admin JWT session, and issuance is admin-only
        // already, so it may reach the admin shell.
        $admin = UserFactory::createOne(['roles' => ['ROLE_ADMIN', 'ROLE_USER']]);
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::Admin->value]]);

        $this->withToken($issued['plainToken'])->request('GET', '/api/v1/admin/users');

        self::assertResponseStatusCodeSame(200);
    }

    public function testNonAdminScopedPatStillDeniedAdminShell(): void
    {
        $admin = UserFactory::createOne(['roles' => ['ROLE_ADMIN', 'ROLE_USER']]);
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::ProfileRead->value]]);

        $response = $this->withToken($issued['plainToken'])->request('GET', '/api/v1/admin/users');

        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString('admin', $headers['www-authenticate'][0] ?? '');
    }

    public function testJwtPathIsUnaffectedByScopes(): void
    {
        // JWT-authenticated request → ScopeListener should skip enforcement
        // entirely; the endpoint runs as if no scope tagging existed.
        $user = UserFactory::createOne();

        $this->jsonClient($user)->request('GET', '/api/v1/me');

        self::assertResponseStatusCodeSame(200);
    }

    public function testUntaggedEndpointDenied(): void
    {
        // `POST /api/users` (registration) has no scope declared. PAT auth
        // can't reach unmarked endpoints — safe default until tagging is
        // complete. JWT or anonymous use is fine.
        $user = UserFactory::createOne();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ProfileWrite->value]]);

        $this->withToken($issued['plainToken'])->request('POST', '/api/v1/users', [
            'json' => ['email' => 'new@example.com', 'password' => 'whatever'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    private function withToken(string $token): Client
    {
        return static::createClient(defaultOptions: [
            'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/ld+json'],
        ]);
    }
}
