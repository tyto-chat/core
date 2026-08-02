<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\AdminAuditLog;
use App\Enum\Admin\AdminAuditAction;
use App\Enum\ApiKey\ApiKeyScope;
use App\Tests\Factory\ApiKeyFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AdminBotApiKeysTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testAdminIssuesKeyForBotReturnsPlainTokenOnce(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $bot = UserFactory::new()->bot()->create();

        $response = $this->jsonClient($admin)->request(
            'POST',
            '/api/v1/admin/users/'.$bot->getId().'/api-keys',
            ['json' => [
                'name' => 'CI bot key',
                'scopes' => [ApiKeyScope::MessagesRead->value, ApiKeyScope::MessagesWrite->value],
            ]],
        );

        self::assertResponseStatusCodeSame(201);
        $body = $response->toArray();
        self::assertSame('CI bot key', $body['name']);
        self::assertStringStartsWith('pat_', $body['plainToken']);

        $list = $this->plainJsonClient($admin)
            ->request('GET', '/api/v1/admin/users/'.$bot->getId().'/api-keys')
            ->toArray();
        $names = array_column($list['rows'], 'name');
        self::assertContains('CI bot key', $names);
    }

    public function testIssuedTokenAuthenticates(): void
    {
        $bot = UserFactory::new()->bot()->create();
        $issued = ApiKeyFactory::createWithToken($bot, ['scopes' => [ApiKeyScope::ProfileRead->value]]);

        $me = static::createClient(defaultOptions: [
            'headers' => ['Authorization' => 'Bearer '.$issued['plainToken']],
        ])->request('GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(200);
        self::assertSame($bot->getId(), $me->toArray()['id']);
    }

    public function testIssueForNonBotIsRejected(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $human = UserFactory::createOne();

        $this->jsonClient($admin)->request(
            'POST',
            '/api/v1/admin/users/'.$human->getId().'/api-keys',
            ['json' => ['name' => 'nope', 'scopes' => [ApiKeyScope::ProfileRead->value]]],
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testIssueRequiresAdmin(): void
    {
        $user = UserFactory::createOne();
        $bot = UserFactory::new()->bot()->create();

        $this->jsonClient($user)->request(
            'POST',
            '/api/v1/admin/users/'.$bot->getId().'/api-keys',
            ['json' => ['name' => 'nope', 'scopes' => [ApiKeyScope::ProfileRead->value]]],
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testIssueRecordsAuditRow(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $bot = UserFactory::new()->bot()->create();

        $this->jsonClient($admin)->request(
            'POST',
            '/api/v1/admin/users/'.$bot->getId().'/api-keys',
            ['json' => ['name' => 'audited', 'scopes' => [ApiKeyScope::ProfileRead->value]]],
        );
        self::assertResponseStatusCodeSame(201);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $audit = $em->getRepository(AdminAuditLog::class)
            ->findOneBy(['action' => AdminAuditAction::UserIssueApiKey]);
        self::assertNotNull($audit);
    }
}
