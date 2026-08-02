<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class CommunityTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testAnonymousCanViewPublicCommunity(): void
    {
        CommunityFactory::new()->withIdentifier('pub-community')->create();

        $this->jsonClient()->request('GET', '/api/v1/communities/pub-community');

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['identifier' => 'pub-community']);
    }

    public function testAnonymousCannotViewPrivateCommunity(): void
    {
        CommunityFactory::new()->private()->withIdentifier('secret')->create();

        $this->jsonClient()->request('GET', '/api/v1/communities/secret');

        self::assertResponseStatusCodeSame(401);
    }

    public function testMemberCanViewPrivateCommunity(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('priv')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        $this->jsonClient($user)->request('GET', '/api/v1/communities/priv');

        self::assertResponseIsSuccessful();
    }

    public function testNonMemberCannotViewPrivateCommunity(): void
    {
        $user = UserFactory::createOne();
        CommunityFactory::new()->private()->withIdentifier('priv2')->create();

        $this->jsonClient($user)->request('GET', '/api/v1/communities/priv2');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminCanCreateCommunity(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->jsonClient($admin)->request('POST', '/api/v1/communities', ['json' => [
            'name' => 'My Community',
            'identifier' => 'my-community',
            'description' => 'A test community',
            'isPrivate' => false,
        ]]);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains(['identifier' => 'my-community']);
    }

    public function testRegularUserCannotCreateCommunity(): void
    {
        $user = UserFactory::createOne();

        $this->jsonClient($user)->request('POST', '/api/v1/communities', ['json' => [
            'name' => 'My Community',
            'isPrivate' => false,
        ]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanUpdateCommunity(): void
    {
        $admin = UserFactory::new()->admin()->create();
        CommunityFactory::new()->withIdentifier('editable')->create();

        $this->jsonClient($admin)->request('PATCH', '/api/v1/communities/editable', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Updated Name'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => 'Updated Name']);
    }

    public function testRegularUserCannotUpdateCommunity(): void
    {
        $user = UserFactory::createOne();
        CommunityFactory::new()->withIdentifier('readonly')->create();

        $this->jsonClient($user)->request('PATCH', '/api/v1/communities/readonly', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Hacked'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanDeleteCommunity(): void
    {
        $admin = UserFactory::new()->admin()->create();
        CommunityFactory::new()->withIdentifier('to-delete')->create();

        $this->jsonClient($admin)->request('DELETE', '/api/v1/communities/to-delete');

        self::assertResponseStatusCodeSame(204);
    }

    public function testUserCanJoinPublicCommunity(): void
    {
        $user = UserFactory::createOne();
        CommunityFactory::new()->withIdentifier('open')->create();

        $this->jsonClient($user)->request('POST', '/api/v1/communities/open/members');

        self::assertResponseStatusCodeSame(204);
    }

    public function testAnonymousCannotJoinCommunity(): void
    {
        CommunityFactory::new()->withIdentifier('open2')->create();

        $this->jsonClient()->request('POST', '/api/v1/communities/open2/members');

        self::assertResponseStatusCodeSame(401);
    }

    public function testUserCannotJoinPrivateCommunity(): void
    {
        $user = UserFactory::createOne();
        CommunityFactory::new()->private()->withIdentifier('closed')->create();

        $this->jsonClient($user)->request('POST', '/api/v1/communities/closed/members');

        self::assertResponseStatusCodeSame(403);
    }

    public function testJoiningTwiceReturnsError(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('already-joined')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        $this->jsonClient($user)->request('POST', '/api/v1/communities/already-joined/members');

        self::assertResponseStatusCodeSame(409);
    }

    public function testUserCanLeaveCommunity(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('leavable')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        $this->jsonClient($user)->request('DELETE', '/api/v1/communities/leavable/members');

        self::assertResponseStatusCodeSame(204);
    }

    public function testAdminCannotLeaveCommunity(): void
    {
        $admin = UserFactory::new()->admin()->create();
        CommunityFactory::new()->withIdentifier('admin-community')->create();

        $this->jsonClient($admin)->request('DELETE', '/api/v1/communities/admin-community/members');

        self::assertResponseStatusCodeSame(409);
    }

    public function testUserCannotLeaveIfNotMember(): void
    {
        $user = UserFactory::createOne();
        CommunityFactory::new()->withIdentifier('not-joined')->create();

        $this->jsonClient($user)->request('DELETE', '/api/v1/communities/not-joined/members');

        self::assertResponseStatusCodeSame(409);
    }

    public function testCommunityAdminCanLeaveWhenOtherAdminsRemain(): void
    {
        $adminA = UserFactory::createOne();
        $adminB = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('co-admins')->create();
        CommunityMemberFactory::createAdminForCommunity($adminA, $community);
        CommunityMemberFactory::createAdminForCommunity($adminB, $community);

        $this->jsonClient($adminA)->request('DELETE', '/api/v1/communities/co-admins/members');

        self::assertResponseStatusCodeSame(204);
    }

    public function testLastCommunityAdminCannotLeave(): void
    {
        $admin = UserFactory::createOne();
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('only-admin')->create();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);
        CommunityMemberFactory::createForUserAndCommunity($member, $community);

        $this->jsonClient($admin)->request('DELETE', '/api/v1/communities/only-admin/members');

        self::assertResponseStatusCodeSame(422);
    }

    public function testMemberCanGetMembersList(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('listed')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        $this->jsonClient($user)->request('GET', '/api/v1/communities/listed/members');

        self::assertResponseIsSuccessful();
    }

    public function testAnonymousCannotGetMembersList(): void
    {
        CommunityFactory::new()->withIdentifier('anon-members')->create();

        $this->jsonClient()->request('GET', '/api/v1/communities/anon-members/members');

        self::assertResponseStatusCodeSame(401);
    }

    public function testMemberCanSeeAdminInMembersList(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('with-admin')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/with-admin/members');

        self::assertResponseIsSuccessful();
        $userIds = array_column(json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR)['hydra:member'], 'userId');
        self::assertContains($admin->getId(), $userIds, 'Admin should appear in the members list for regular users');
    }
}
