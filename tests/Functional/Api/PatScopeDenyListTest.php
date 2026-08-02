<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Enum\ApiKey\ApiKeyScope;
use App\Tests\Factory\ApiKeyFactory;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class PatScopeDenyListTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function denyListProvider(): iterable
    {
        yield 'register' => ['POST', '/api/v1/users'];
        yield 'registration challenge' => ['POST', '/api/v1/challenges'];
        yield 'reset password request' => ['POST', '/api/v1/reset_password'];
        yield 'reset password confirm' => ['POST', '/api/v1/password'];
        yield 'change password' => ['POST', '/api/v1/users/me/change-password'];
        yield 'api-keys list' => ['GET', '/api/v1/me/api-keys'];
        yield 'api-keys issue' => ['POST', '/api/v1/me/api-keys'];
        yield 'api-keys revoke' => ['DELETE', '/api/v1/me/api-keys/1'];
        yield 'account deletion status' => ['GET', '/api/v1/me/account-deletion'];
        yield 'account deletion request' => ['POST', '/api/v1/me/account-deletion'];
        yield 'account deletion cancel' => ['DELETE', '/api/v1/me/account-deletion'];
        yield 'data export status' => ['GET', '/api/v1/me/data-export'];
        yield 'data export request' => ['POST', '/api/v1/me/data-export'];
        yield 'data export download' => ['GET', '/api/v1/me/data-export/download'];
        yield 'realtime token' => ['GET', '/api/v1/realtime/token'];
        yield 'realtime public token' => ['GET', '/api/v1/realtime/public-token'];
        yield 'presence set' => ['POST', '/api/v1/me/presence'];
        yield 'presence offline' => ['POST', '/api/v1/me/presence/offline'];
        yield 'onboarding complete' => ['POST', '/api/v1/me/onboarding/complete'];
        yield 'admin onboarding complete' => ['POST', '/api/v1/admin/server-config/onboarding/complete'];
        yield 'http-cache context-hash' => ['GET', '/api/http-cache/context-hash'];
        yield '2fa status' => ['GET', '/api/v1/users/me/2fa'];
        yield '2fa setup' => ['POST', '/api/v1/users/me/2fa/setup'];
        yield '2fa confirm' => ['POST', '/api/v1/users/me/2fa/confirm'];
        yield '2fa disable' => ['POST', '/api/v1/users/me/2fa/disable'];
        yield '2fa recovery codes' => ['POST', '/api/v1/users/me/2fa/recovery-codes'];
    }

    #[DataProvider('denyListProvider')]
    public function testDenyListRowRejectsFullyScopedKey(string $method, string $path): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_ADMIN', 'ROLE_USER']]);
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => array_map(
            static fn (ApiKeyScope $scope): string => $scope->value,
            ApiKeyScope::cases(),
        )]);

        $response = $this->withToken($issued['plainToken'])->request($method, $path);

        self::assertResponseStatusCodeSame(403, sprintf('%s %s should be denied to any PAT.', $method, $path));
        self::assertStringContainsString('does not declare', $response->getContent(false));
    }

    public function testVoiceCallTokenDeniedToFullyScopedKey(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_ADMIN', 'ROLE_USER']]);
        $community = CommunityFactory::createOne();
        $channel = ChannelFactory::new()->inCommunity($community)->audio()->create();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => array_map(
            static fn (ApiKeyScope $scope): string => $scope->value,
            ApiKeyScope::cases(),
        )]);

        $response = $this->withToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/communities/'.$community->getIdentifier().'/channels/'.$channel->getIdentifier().'/call/token',
        );

        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('does not declare', $response->getContent(false));
    }

    public function testVoiceCallLeaveDeniedToFullyScopedKey(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_ADMIN', 'ROLE_USER']]);
        $community = CommunityFactory::createOne();
        $channel = ChannelFactory::new()->inCommunity($community)->audio()->create();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => array_map(
            static fn (ApiKeyScope $scope): string => $scope->value,
            ApiKeyScope::cases(),
        )]);

        $response = $this->withToken($issued['plainToken'])->request(
            'DELETE',
            '/api/v1/communities/'.$community->getIdentifier().'/channels/'.$channel->getIdentifier().'/call',
        );

        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('does not declare', $response->getContent(false));
    }

    public function testTokenRefreshSkippedApiKeyAuthenticatorNotWiredToThatFirewall(): void
    {
        self::markTestSkipped('The `refresh` firewall (pattern ^/token/refresh) has no ApiKeyAuthenticator, so a PAT bearer token never authenticates there; the scope listener never runs and this row cannot assert 403. Confirmed empirically: POST /token/refresh with a fully-scoped PAT returns 401 "Missing JWT Refresh Token", not 403.');
    }

    public function testLogoutSkippedApiKeyAuthenticatorNotWiredToThatFirewall(): void
    {
        self::markTestSkipped('The `main` firewall (default, matches /logout) has no ApiKeyAuthenticator, so a PAT bearer token never authenticates there; the scope listener never runs and this row cannot assert 403. Confirmed empirically: POST /logout with a fully-scoped PAT returns 204 (treated as an anonymous logout), not 403.');
    }

    private function withToken(string $token): Client
    {
        return static::createClient(defaultOptions: [
            'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/ld+json'],
        ]);
    }
}
