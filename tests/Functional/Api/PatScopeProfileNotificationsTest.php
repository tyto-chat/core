<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Enum\ApiKey\ApiKeyScope;
use App\Tests\Factory\ApiKeyFactory;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class PatScopeProfileNotificationsTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testProfileReadCanGetPreferences(): void
    {
        $user = UserFactory::createOne();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ProfileRead->value]]);

        $this->withToken($issued['plainToken'])->request('GET', '/api/v1/me/preferences');

        self::assertResponseStatusCodeSame(200);
    }

    public function testProfileWriteCanPatchPreferences(): void
    {
        $user = UserFactory::createOne();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ProfileWrite->value]]);

        $this->withToken($issued['plainToken'])->request('PATCH', '/api/v1/me/preferences', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['theme' => 'dark'],
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testProfileReadCannotPatchPreferences(): void
    {
        $user = UserFactory::createOne();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ProfileRead->value]]);

        $response = $this->withToken($issued['plainToken'])->request('PATCH', '/api/v1/me/preferences', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['theme' => 'dark'],
        ]);

        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString('profile:write', $headers['www-authenticate'][0] ?? '');
    }

    public function testWrongScopeRejectedForPreferences(): void
    {
        $user = UserFactory::createOne();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::NotificationsRead->value]]);

        $response = $this->withToken($issued['plainToken'])->request('GET', '/api/v1/me/preferences');

        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString('profile:read', $headers['www-authenticate'][0] ?? '');
    }

    public function testProfileWriteWrongScopeRejectedForAvatarUpload(): void
    {
        $user = UserFactory::createOne();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ProfileRead->value]]);

        $response = $this->withToken($issued['plainToken'])->request('POST', '/api/v1/me/avatar', [
            'headers' => ['Content-Type' => 'multipart/form-data'],
        ]);

        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString('profile:write', $headers['www-authenticate'][0] ?? '');
    }

    public function testNotificationsReadCanListMeNotifications(): void
    {
        $user = UserFactory::createOne();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::NotificationsRead->value]]);

        $this->withToken($issued['plainToken'])->request('GET', '/api/v1/me/notifications');

        self::assertResponseStatusCodeSame(200);
    }

    public function testNotificationsWriteCanMarkAllRead(): void
    {
        $user = UserFactory::createOne();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::NotificationsWrite->value]]);

        $this->withToken($issued['plainToken'])->request('POST', '/api/v1/me/notifications/mark-all-read');

        self::assertResponseStatusCodeSame(204);
    }

    public function testNotificationsReadCannotMarkAllRead(): void
    {
        $user = UserFactory::createOne();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::NotificationsRead->value]]);

        $response = $this->withToken($issued['plainToken'])->request('POST', '/api/v1/me/notifications/mark-all-read');

        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString('notifications:write', $headers['www-authenticate'][0] ?? '');
    }

    public function testWrongScopeRejectedForNotifications(): void
    {
        $user = UserFactory::createOne();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ProfileRead->value]]);

        $response = $this->withToken($issued['plainToken'])->request('GET', '/api/v1/me/notifications');

        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString('notifications:read', $headers['www-authenticate'][0] ?? '');
    }

    public function testPushWriteCanSubscribe(): void
    {
        $user = UserFactory::createOne();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::PushWrite->value]]);

        $this->withToken($issued['plainToken'])->request('POST', '/api/v1/me/push-subscriptions', [
            'json' => [
                'endpoint' => 'https://push.example.com/'.bin2hex(random_bytes(8)),
                'keys' => ['p256dh' => 'test-p256dh', 'auth' => 'test-auth'],
                'locale' => 'en',
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testWrongScopeRejectedForPushSubscribe(): void
    {
        $user = UserFactory::createOne();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ProfileWrite->value]]);

        $response = $this->withToken($issued['plainToken'])->request('POST', '/api/v1/me/push-subscriptions', [
            'json' => [
                'endpoint' => 'https://push.example.com/'.bin2hex(random_bytes(8)),
                'keys' => ['p256dh' => 'test-p256dh', 'auth' => 'test-auth'],
                'locale' => 'en',
            ],
        ]);

        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString('push:write', $headers['www-authenticate'][0] ?? '');
    }

    public function testWrongScopeRejectedForPushUnsubscribe(): void
    {
        $user = UserFactory::createOne();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ProfileWrite->value]]);

        $response = $this->withToken($issued['plainToken'])->request('DELETE', '/api/v1/me/push-subscriptions?endpoint=https://push.example.com/gone');

        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString('push:write', $headers['www-authenticate'][0] ?? '');
    }

    public function testNotificationsWriteCanMarkCommunityNotificationsRead(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::NotificationsWrite->value]]);

        $this->withToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/communities/'.$community->getIdentifier().'/notifications/mark-all-read',
        );

        self::assertResponseStatusCodeSame(204);
    }

    public function testWrongScopeRejectedForCommunityNotificationsMarkAllRead(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ProfileRead->value]]);

        $response = $this->withToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/communities/'.$community->getIdentifier().'/notifications/mark-all-read',
        );

        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString('notifications:write', $headers['www-authenticate'][0] ?? '');
    }

    public function testNotificationsWriteCanSetChannelNotificationLevel(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::createOne();
        $channel = ChannelFactory::new()->inCommunity($community)->create();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::NotificationsWrite->value]]);

        $this->withToken($issued['plainToken'])->request(
            'PUT',
            '/api/v1/communities/'.$community->getIdentifier().'/channels/'.$channel->getIdentifier().'/notification-preference',
            [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => ['level' => 'all'],
            ],
        );

        self::assertResponseIsSuccessful();
    }

    public function testWrongScopeRejectedForChannelNotificationPreference(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::createOne();
        $channel = ChannelFactory::new()->inCommunity($community)->create();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ProfileRead->value]]);

        $response = $this->withToken($issued['plainToken'])->request(
            'PUT',
            '/api/v1/communities/'.$community->getIdentifier().'/channels/'.$channel->getIdentifier().'/notification-preference',
            [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => ['level' => 'all'],
            ],
        );

        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString('notifications:write', $headers['www-authenticate'][0] ?? '');
    }

    public function testWrongScopeRejectedForCommunityNotificationPreference(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::createOne();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ProfileRead->value]]);

        $response = $this->withToken($issued['plainToken'])->request(
            'PUT',
            '/api/v1/communities/'.$community->getIdentifier().'/notification-preference',
            [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => ['muted' => true],
            ],
        );

        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString('notifications:write', $headers['www-authenticate'][0] ?? '');
    }

    private function withToken(string $token): Client
    {
        return static::createClient(defaultOptions: [
            'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/ld+json'],
        ]);
    }
}
