<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\Admin\AdminAuditAction;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class AdminAuditTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testListReturns403ForNonAdmin(): void
    {
        $user = UserFactory::createOne();

        $this->plainJsonClient($user)->request('GET', '/api/v1/admin/audit-log');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminActionAppearsInAuditLog(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();

        // Trigger an audited write: promote a user to admin.
        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/users/'.$target->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['isAdmin' => true],
        ]);
        self::assertResponseStatusCodeSame(200);

        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/audit-log');

        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertGreaterThanOrEqual(1, $body['total']);
        $row = $body['rows'][0];
        self::assertSame(AdminAuditAction::UserPromote->value, $row['action']);
        self::assertSame('user', $row['targetType']);
        self::assertSame($target->getId(), $row['targetId']);
        self::assertSame($admin->getId(), $row['actor']['id']);
    }

    public function testFilterByActionExcludesOthers(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();

        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/users/'.$target->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['isAdmin' => true],
        ]);

        // No CommunityDelete actions exist → filtered list is empty.
        $response = $this->plainJsonClient($admin)->request(
            'GET',
            '/api/v1/admin/audit-log?action='.AdminAuditAction::CommunityDelete->value,
        );

        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $response->toArray()['total']);
    }

    public function testUnknownActionFilterIsRejected(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/audit-log?action=not_a_real_action');

        self::assertResponseStatusCodeSame(400);
    }

    public function testActionsVocabularyEndpoint(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/audit-log/actions');

        self::assertResponseStatusCodeSame(200);
        $actions = $response->toArray()['actions'];
        self::assertContains(AdminAuditAction::UserForceDelete->value, $actions);
    }
}
