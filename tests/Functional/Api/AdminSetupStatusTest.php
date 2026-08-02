<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AdminSetupStatusTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testReturns401ForAnonymous(): void
    {
        static::createClient()->request('GET', '/api/v1/admin/setup-status');
        self::assertResponseStatusCodeSame(401);
    }

    public function testReturns403ForNonAdmin(): void
    {
        $user = UserFactory::createOne();
        $this->plainJsonClient($user)->request('GET', '/api/v1/admin/setup-status');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminGetsItemsAndNeedsAttentionOnFreshInstall(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/setup-status');
        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertArrayHasKey('needsAttention', $body);
        self::assertArrayHasKey('items', $body);
        self::assertCount(4, $body['items']);
        $keys = array_map(static fn ($i) => $i['key'], $body['items']);
        self::assertEqualsCanonicalizing(['smtp', 'defaultBot', 'mercure', 'meilisearch'], $keys);
        self::assertTrue($body['needsAttention']);
    }

    public function testSmtpItemBecomesSatisfiedAfterConfiguring(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['smtpHost' => 'smtp.example.org'],
        ]);
        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/setup-status');
        $body = $response->toArray();
        $smtp = array_values(array_filter($body['items'], static fn ($i) => 'smtp' === $i['key']))[0];
        self::assertTrue($smtp['satisfied']);
    }
}
