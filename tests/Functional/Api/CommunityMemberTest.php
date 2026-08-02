<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class CommunityMemberTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testMemberCanListCommunityMembers(): void
    {
        $user = UserFactory::createOne();
        $other = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cm-list')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        CommunityMemberFactory::createForUserAndCommunity($other, $community);

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/cm-list/members');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertCount(2, $data['hydra:member']);
    }

    public function testAnyAuthenticatedUserCanListCommunityMembers(): void
    {
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cm-open')->create();
        $member = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);

        $response = $this->jsonClient($outsider)->request('GET', '/api/v1/communities/cm-open/members');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertCount(1, $data['hydra:member']);
    }

    public function testAnonymousCannotListCommunityMembers(): void
    {
        CommunityFactory::new()->withIdentifier('cm-anon')->create();

        $this->jsonClient()->request('GET', '/api/v1/communities/cm-anon/members');

        self::assertResponseStatusCodeSame(401);
    }

    public function testCommunityAdminCanAddMemberToPrivateCommunity(): void
    {
        $admin = UserFactory::createOne();
        $newcomer = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('cm-add-priv')->create();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);

        $this->jsonClient($admin)->request('POST', '/api/v1/communities/cm-add-priv/members/add', [
            'json' => ['userId' => $newcomer->getId()],
        ]);

        self::assertResponseStatusCodeSame(201);

        // The added user can now view the private community.
        $this->jsonClient($newcomer)->request('GET', '/api/v1/communities/cm-add-priv');
        self::assertResponseIsSuccessful();
    }

    public function testNonAdminCannotAddMember(): void
    {
        $member = UserFactory::createOne();
        $newcomer = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('cm-add-deny')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);

        $this->jsonClient($member)->request('POST', '/api/v1/communities/cm-add-deny/members/add', [
            'json' => ['userId' => $newcomer->getId()],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testGlobalAdminCanAddMember(): void
    {
        $globalAdmin = UserFactory::new()->admin()->create();
        $newcomer = UserFactory::createOne();
        CommunityFactory::new()->private()->withIdentifier('cm-add-global')->create();

        $this->jsonClient($globalAdmin)->request('POST', '/api/v1/communities/cm-add-global/members/add', [
            'json' => ['userId' => $newcomer->getId()],
        ]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testAddMemberIsIdempotent(): void
    {
        $admin = UserFactory::createOne();
        $existing = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cm-add-idem')->create();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);
        CommunityMemberFactory::createForUserAndCommunity($existing, $community);

        $this->jsonClient($admin)->request('POST', '/api/v1/communities/cm-add-idem/members/add', [
            'json' => ['userId' => $existing->getId()],
        ]);

        self::assertResponseStatusCodeSame(201);

        // Still exactly two members (admin + existing) — no duplicate row.
        $response = $this->jsonClient($admin)->request('GET', '/api/v1/communities/cm-add-idem/members');
        self::assertCount(2, $response->toArray()['hydra:member']);
    }

    public function testAddMemberAppliesGivenRole(): void
    {
        $admin = UserFactory::createOne();
        $newcomer = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cm-add-role')->create();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);

        $this->jsonClient($admin)->request('POST', '/api/v1/communities/cm-add-role/members/add', [
            'json' => ['userId' => $newcomer->getId(), 'role' => 'moderator'],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains(['role' => 'moderator']);
    }

    public function testAddMemberWithNonPositiveUserIdReturns422(): void
    {
        $admin = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('cm-add-bad')->create();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);

        $this->jsonClient($admin)->request('POST', '/api/v1/communities/cm-add-bad/members/add', [
            'json' => ['userId' => 0],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAddMemberWithUnknownUserReturns404(): void
    {
        $admin = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('cm-add-unknown')->create();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);

        $this->jsonClient($admin)->request('POST', '/api/v1/communities/cm-add-unknown/members/add', [
            'json' => ['userId' => 999999999],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testUpdateRoleForForeignCommunityMemberIsDenied(): void
    {
        $adminA = UserFactory::createOne();
        $communityA = CommunityFactory::new()->withIdentifier('cm-role-a')->create();
        CommunityMemberFactory::createAdminForCommunity($adminA, $communityA);

        // A member of a DIFFERENT community — its id must not resolve under
        // community A's members endpoint.
        $memberB = UserFactory::createOne();
        $communityB = CommunityFactory::new()->withIdentifier('cm-role-b')->create();
        $foreignMember = CommunityMemberFactory::createForUserAndCommunity($memberB, $communityB);

        $this->jsonClient($adminA)->request(
            'PATCH',
            '/api/v1/communities/cm-role-a/members/'.$foreignMember->getId(),
            [
                'json' => ['role' => 'admin'],
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
            ],
        );

        self::assertResponseStatusCodeSame(409);
    }
}
