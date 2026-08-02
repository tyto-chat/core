<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class HealthEndpointTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testPublicHealthIsAnonAccessible(): void
    {
        $response = static::createClient()->request('GET', '/api/health');

        // Real probes hit DB + Redis — both up in CI, so we expect 200.
        // Mercure / Meilisearch may be down in the test stack; we only
        // assert that the endpoint responds with 200 or 503 (not 500),
        // i.e. the controller never bubbles an exception.
        $status = $response->getStatusCode();
        self::assertContains($status, [200, 503], 'Public health endpoint should return 200 or 503, got '.$status);
    }

    public function testAdminHealthReturns403ForNonAdmin(): void
    {
        $user = UserFactory::createOne();

        $this->plainJsonClient($user)->request('GET', '/api/v1/admin/health');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminHealthReturns401ForAnonymous(): void
    {
        static::createClient()->request('GET', '/api/v1/admin/health');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAdminHealthReturnsDetailForAdmin(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/health');

        self::assertSame(200, $response->getStatusCode());
        $body = $response->toArray();
        self::assertArrayHasKey('overall', $body);
        self::assertArrayHasKey('checks', $body);
        // Six probes: 4 liveness (database, redis, meilisearch, mercure) +
        // 2 config-completeness (mercure-config, meilisearch-config).
        self::assertCount(6, $body['checks']);
        $names = array_column($body['checks'], 'name');
        self::assertContains('database', $names);
        self::assertContains('redis', $names);
        self::assertContains('meilisearch', $names);
        self::assertContains('mercure', $names);
        self::assertContains('mercure-config', $names);
        self::assertContains('meilisearch-config', $names);
    }

    public function testAdminHealthListsScheduledTasks(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $body = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/health')->toArray();

        self::assertArrayHasKey('scheduledTasks', $body);
        self::assertNotEmpty($body['scheduledTasks']);
        $task = $body['scheduledTasks'][0];
        foreach (['name', 'description', 'nextRunAt', 'lastRunAt', 'lastStatus'] as $key) {
            self::assertArrayHasKey($key, $task);
        }
        // Every declared task computes a next run; none have run in the test.
        self::assertNotNull($task['nextRunAt']);
        $names = array_column($body['scheduledTasks'], 'name');
        self::assertContains('ReconcileAudioParticipants', $names);
        self::assertContains('PurgeRetention', $names);
    }
}
