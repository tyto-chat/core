<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\User;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class AdminUsersTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testListReturns403ForNonAdmin(): void
    {
        $user = UserFactory::createOne();

        $this->plainJsonClient($user)->request('GET', '/api/v1/admin/users');

        self::assertResponseStatusCodeSame(403);
    }

    public function testListReturnsRowsForAdmin(): void
    {
        $admin = UserFactory::new()->admin()->create();
        UserFactory::createMany(3);

        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/users?perPage=10');

        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertArrayHasKey('rows', $body);
        self::assertArrayHasKey('total', $body);
        // The admin + 3 factory users = 4 total.
        self::assertSame(4, $body['total']);
    }

    public function testListSortsByEmail(): void
    {
        $admin = UserFactory::new()->admin()->create(['email' => 'bbb@example.com']);
        UserFactory::createOne(['email' => 'aaa@example.com']);
        UserFactory::createOne(['email' => 'zzz@example.com']);

        $body = $this->plainJsonClient($admin)
            ->request('GET', '/api/v1/admin/users?sort=email&dir=ASC')
            ->toArray();

        $emails = array_column($body['rows'], 'email');
        self::assertSame(['aaa@example.com', 'bbb@example.com', 'zzz@example.com'], $emails);
    }

    public function testListIgnoresUnknownSortColumn(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/users?sort=evil;drop&dir=ASC');

        self::assertResponseStatusCodeSame(200);
    }

    public function testListFiltersBySearchTerm(): void
    {
        $admin = UserFactory::new()->admin()->create();
        UserFactory::createOne(['email' => 'distinct-needle@example.com']);
        UserFactory::createMany(2);

        $response = $this->plainJsonClient($admin)->request(
            'GET',
            '/api/v1/admin/users?search=distinct-needle',
        );

        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertSame(1, $body['total']);
        self::assertSame('distinct-needle@example.com', $body['rows'][0]['email']);
    }

    public function testPatchPromotesAndDemotes(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();

        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/users/'.$target->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['isAdmin' => true],
        ]);
        self::assertResponseStatusCodeSame(200);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($target->getId());
        self::assertNotNull($reloaded);
        self::assertContains('ROLE_ADMIN', $reloaded->getRoles());

        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/users/'.$target->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['isAdmin' => false],
        ]);
        self::assertResponseStatusCodeSame(200);
        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($target->getId());
        self::assertNotNull($reloaded);
        self::assertNotContains('ROLE_ADMIN', $reloaded->getRoles());
    }

    public function testDeleteAnonymisesUserInPlace(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne(['email' => 'will-be-purged@example.com']);
        $targetId = $target->getId();

        $this->plainJsonClient($admin)->request('DELETE', '/api/v1/admin/users/'.$targetId, [
            'json' => ['confirm' => 'will-be-purged@example.com'],
        ]);

        self::assertResponseStatusCodeSame(204);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
        $purged = $em->getRepository(User::class)->find($targetId);
        self::assertNotNull($purged);
        self::assertSame(sprintf('deleted-%d@invalid.local', $targetId), $purged->getEmail());
        self::assertTrue($purged->isBot());
    }

    public function testDeleteRejectsSelf(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->plainJsonClient($admin)->request('DELETE', '/api/v1/admin/users/'.$admin->getId());

        self::assertResponseStatusCodeSame(400);
    }

    public function testDeleteRequiresMatchingConfirmation(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne(['email' => 'keep-me@example.com']);
        $targetId = $target->getId();

        $this->plainJsonClient($admin)->request('DELETE', '/api/v1/admin/users/'.$targetId);
        self::assertResponseStatusCodeSame(400);

        $this->plainJsonClient($admin)->request('DELETE', '/api/v1/admin/users/'.$targetId, [
            'json' => ['confirm' => 'wrong@example.com'],
        ]);
        self::assertResponseStatusCodeSame(400);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
        $survivor = $em->getRepository(User::class)->find($targetId);
        self::assertNotNull($survivor);
        self::assertSame('keep-me@example.com', $survivor->getEmail());
    }

    public function testDetailReturnsCountsAndRoles(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();

        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/users/'.$target->getId());

        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertArrayHasKey('apiKeyCount', $body);
        self::assertArrayHasKey('pushSubscriptionCount', $body);
        self::assertArrayHasKey('roles', $body);
        self::assertSame($target->getId(), $body['id']);
    }

    public function testDetailReturns404ForUnknownUser(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/users/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testPatchUpdatesDisplayName(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();

        $response = $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/users/'.$target->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['displayName' => 'Renamed Person'],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Renamed Person', $response->toArray()['displayName']);
    }

    public function testListApiKeysReturnsRows(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();
        \App\Tests\Factory\ApiKeyFactory::createOne(['user' => $target, 'name' => 'CI token']);

        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/users/'.$target->getId().'/api-keys');

        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertArrayHasKey('rows', $body);
        self::assertCount(1, $body['rows']);
        self::assertSame('CI token', $body['rows'][0]['name']);
        self::assertArrayNotHasKey('token', $body['rows'][0]);
    }

    public function testRevokeApiKey(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();
        $key = \App\Tests\Factory\ApiKeyFactory::createOne(['user' => $target]);

        $this->plainJsonClient($admin)->request('DELETE', '/api/v1/admin/users/'.$target->getId().'/api-keys/'.$key->getId());

        self::assertResponseStatusCodeSame(204);
    }

    public function testRevokeApiKeyOfOtherUserReturns404(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();
        $other = UserFactory::createOne();
        $key = \App\Tests\Factory\ApiKeyFactory::createOne(['user' => $other]);

        // Key belongs to $other, not $target → 404.
        $this->plainJsonClient($admin)->request('DELETE', '/api/v1/admin/users/'.$target->getId().'/api-keys/'.$key->getId());

        self::assertResponseStatusCodeSame(404);
    }

    public function testListFiltersByIsBot(): void
    {
        $admin = UserFactory::new()->admin()->create();
        UserFactory::createOne(); // a human
        UserFactory::new()->bot()->create();

        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/users?isBot=true&perPage=100');
        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertGreaterThanOrEqual(1, $body['total']);
        foreach ($body['rows'] as $row) {
            self::assertTrue($row['isBot']);
        }
    }
}
