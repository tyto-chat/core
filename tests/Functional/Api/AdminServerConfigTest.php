<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\AdminAuditLog;
use App\Enum\Admin\AdminAuditAction;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class AdminServerConfigTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testGetReturns403ForNonAdmin(): void
    {
        $user = UserFactory::createOne();

        $this->plainJsonClient($user)->request('GET', '/api/v1/admin/server-config');

        self::assertResponseStatusCodeSame(403);
    }

    public function testGetReturns401ForAnonymous(): void
    {
        static::createClient()->request('GET', '/api/v1/admin/server-config');

        self::assertResponseStatusCodeSame(401);
    }

    public function testGetReturnsBootstrappedDefaultsForAdmin(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/server-config');

        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertArrayHasKey('serverName', $body);
        self::assertArrayHasKey('registrationEnabled', $body);
        self::assertTrue($body['registrationEnabled']);
        // SMTP unset on a fresh install: the password-set flag must read false.
        self::assertFalse($body['smtpPasswordSet']);
        self::assertArrayHasKey('ipReputationEnabled', $body);
        self::assertFalse($body['ipReputationEnabled']);
    }

    public function testPatchUpdatesKnownFieldsAndRecordsAudit(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $response = $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => [
                'serverName' => 'My Tyto Server',
                'registrationEnabled' => false,
                'maxAttachmentSizeMb' => 50,
            ],
        ]);

        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertSame('My Tyto Server', $body['serverName']);
        self::assertFalse($body['registrationEnabled']);
        self::assertSame(50, $body['maxAttachmentSizeMb']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $audit = $em->getRepository(AdminAuditLog::class)->findOneBy(['action' => AdminAuditAction::ServerConfigUpdate]);
        self::assertNotNull($audit);
        self::assertSame($admin->getId(), $audit->getActor()?->getId());
    }

    public function testPatchRejectsBadShape(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['serverName' => ''],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testPatchValidatesLocaleEnum(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['defaultLocale' => 'xx'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testServerInfoReflectsRegistrationToggle(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['registrationEnabled' => false],
        ]);

        // server-info is publicly readable; anonymous client should see the flag flip.
        $response = static::createClient()->request('GET', '/api/v1/server-info', [
            'headers' => ['Accept' => 'application/json'],
        ]);

        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertFalse($body['registrationEnabled']);
    }

    public function testPatchMigratedSettingRoundTrips(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['autoTimeoutHits' => 9, 'avatarMaxSizeMb' => 3, 'attachmentAllowedMimes' => 'image/png'],
        ]);
        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/server-config');
        $body = $response->toArray();
        self::assertSame(9, $body['autoTimeoutHits']);
        self::assertSame(3, $body['avatarMaxSizeMb']);
        self::assertSame('image/png', $body['attachmentAllowedMimes']);
    }

    public function testPatchBotRoleSettingsRoundTrip(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['welcomeBotId' => 7, 'autoModeratorBotId' => 8],
        ]);
        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/server-config');
        $body = $response->toArray();
        self::assertSame(7, $body['welcomeBotId']);
        self::assertSame(8, $body['autoModeratorBotId']);
    }

    public function testHttpCachePageTtlDefaultsToZeroAndIsPatchable(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/server-config');
        $config = $response->toArray();
        self::assertSame(0, $config['httpCachePageTtlSeconds']);

        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['httpCachePageTtlSeconds' => 300],
        ]);
        self::assertResponseIsSuccessful();

        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/server-config');
        $config = $response->toArray();
        self::assertSame(300, $config['httpCachePageTtlSeconds']);
    }

    public function testPatchRejectsNegativeHttpCachePageTtl(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['httpCachePageTtlSeconds' => -1],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testPatchIpReputationSettingsRoundTrip(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['ipReputationEnabled' => true, 'ipReputationConfidenceMin' => 50],
        ]);
        self::assertResponseIsSuccessful();

        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/server-config');
        $body = $response->toArray();
        self::assertTrue($body['ipReputationEnabled']);
        self::assertSame(50, $body['ipReputationConfidenceMin']);
    }

    public function testHttpCachePresenceTtlDefaultsToZeroAndIsPatchable(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/server-config');
        $config = $response->toArray();
        self::assertSame(0, $config['httpCachePresenceTtlSeconds']);

        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['httpCachePresenceTtlSeconds' => 15],
        ]);
        self::assertResponseIsSuccessful();

        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/server-config');
        $config = $response->toArray();
        self::assertSame(15, $config['httpCachePresenceTtlSeconds']);
    }

    public function testPatchRejectsNegativeHttpCachePresenceTtl(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['httpCachePresenceTtlSeconds' => -1],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testPatchDiskPurgeSettings(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $response = $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => [
                'diskPurgeTriggerPercent' => 10,
                'diskPurgeTargetPercent' => 20,
                'diskPurgeMinAgeDays' => 7,
                'diskPurgeIncludeDms' => true,
            ],
        ]);

        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertSame(10, $body['diskPurgeTriggerPercent']);
        self::assertSame(20, $body['diskPurgeTargetPercent']);
        self::assertSame(7, $body['diskPurgeMinAgeDays']);
        self::assertTrue($body['diskPurgeIncludeDms']);
    }

    public function testPatchRejectsDiskPurgeTargetNotAboveTrigger(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => [
                'diskPurgeTriggerPercent' => 20,
                'diskPurgeTargetPercent' => 10,
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testFreshInstallReturnsDefaultsWithoutWritingRows(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM setting'));

        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/server-config');
        self::assertResponseStatusCodeSame(200);
        // GET does not seed rows — defaults come from the registry.
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM setting'));

        // First PATCH writes exactly the changed override rows.
        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['serverName' => 'Seeded'],
        ]);
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM setting'));
    }
}
