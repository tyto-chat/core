<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class AdminUserTwoFactorTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testAdminCanDisableUsersTwoFactor(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::new()->withTwoFactor()->create();

        $response = $this->plainJsonClient($admin)->request(
            'POST',
            sprintf('/api/v1/admin/users/%d/2fa/disable', $target->getId()),
            ['json' => []],
        );
        self::assertResponseIsSuccessful();
        self::assertFalse($response->toArray()['twoFactorEnabled']);
    }

    public function testDisableIsAuditLogged(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::new()->withTwoFactor()->create();
        $this->plainJsonClient($admin)->request(
            'POST',
            sprintf('/api/v1/admin/users/%d/2fa/disable', $target->getId()),
            ['json' => []],
        );
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $count = (int) $em->getConnection()
            ->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE action = 'user.two_factor_disable'");
        self::assertSame(1, $count);
    }

    public function testNonAdminCannotDisable(): void
    {
        $user = UserFactory::createOne();
        $target = UserFactory::new()->withTwoFactor()->create();
        $this->plainJsonClient($user)->request(
            'POST',
            sprintf('/api/v1/admin/users/%d/2fa/disable', $target->getId()),
            ['json' => []],
        );
        self::assertResponseStatusCodeSame(403);
    }

    public function testDetailExposesTwoFactorFlag(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::new()->withTwoFactor()->create();
        $response = $this->plainJsonClient($admin)->request('GET', sprintf('/api/v1/admin/users/%d', $target->getId()));
        self::assertTrue($response->toArray()['twoFactorEnabled']);
    }
}
