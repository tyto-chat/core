<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class InvitableUsersTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testReturnsUsersFromSharedCommunities(): void
    {
        $caller = UserFactory::createOne();
        $shared = UserFactory::createOne();
        $stranger = UserFactory::createOne();

        $community = CommunityFactory::new()->withIdentifier('inv-shared')->create();
        CommunityMemberFactory::createForUserAndCommunity($caller, $community);
        CommunityMemberFactory::createForUserAndCommunity($shared, $community);

        $response = $this->plainJsonClient($caller)->request('GET', '/api/v1/me/invitable-users');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        $ids = array_column($data['items'], 'id');

        self::assertContains($shared->getId(), $ids);
        self::assertNotContains($stranger->getId(), $ids);
        self::assertNotContains($caller->getId(), $ids);

        foreach ($data['items'] as $item) {
            self::assertArrayNotHasKey('email', $item, 'Invitable-user search must never leak email addresses.');
        }
    }

    public function testGlobalAdminSeesUsersWithoutSharedCommunity(): void
    {
        // Global admins can DM anyone, so the invitable list is not restricted
        // to shared-community members (they may hold no CommunityMember rows).
        $admin = UserFactory::new()->admin()->create();
        $stranger = UserFactory::createOne();

        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/me/invitable-users');

        self::assertResponseIsSuccessful();
        $ids = array_column($response->toArray()['items'], 'id');

        self::assertContains($stranger->getId(), $ids);
    }

    public function testRequiresAuthentication(): void
    {
        $this->plainJsonClient()->request('GET', '/api/v1/me/invitable-users');

        self::assertResponseStatusCodeSame(401);
    }
}
