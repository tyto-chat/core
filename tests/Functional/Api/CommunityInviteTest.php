<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityInviteFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class CommunityInviteTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testCommunityAdminCanCreateInvite(): void
    {
        $admin = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('inv-create')->create();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);

        $response = $this->jsonClient($admin)->request('POST', '/api/v1/communities/inv-create/invites', [
            'json' => ['maxUses' => 5],
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = $response->toArray();
        self::assertNotEmpty($data['token']);
        self::assertSame(5, $data['maxUses']);
        self::assertSame(0, $data['useCount']);
        self::assertTrue($data['isValid']);
        self::assertSame('inv-create', $data['communityIdentifier']);
    }

    public function testNonAdminCannotCreateInvite(): void
    {
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('inv-deny')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);

        $this->jsonClient($member)->request('POST', '/api/v1/communities/inv-deny/invites', [
            'json' => [],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanListInvites(): void
    {
        $admin = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('inv-list')->create();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);
        CommunityInviteFactory::createOne(['community' => $community]);
        CommunityInviteFactory::createOne(['community' => $community]);

        $response = $this->jsonClient($admin)->request('GET', '/api/v1/communities/inv-list/invites');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $response->toArray()['hydra:member']);
    }

    public function testAdminCanRevokeInvite(): void
    {
        $admin = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('inv-revoke')->create();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);
        $invite = CommunityInviteFactory::createOne(['community' => $community]);

        $this->jsonClient($admin)->request(
            'DELETE',
            '/api/v1/communities/inv-revoke/invites/'.$invite->getId(),
        );

        self::assertResponseStatusCodeSame(204);

        $stranger = UserFactory::createOne();
        $this->jsonClient($stranger)->request('POST', '/api/v1/invites/'.$invite->getToken().'/accept');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAcceptInviteJoinsPrivateCommunity(): void
    {
        $newcomer = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('inv-accept')->create();
        $invite = CommunityInviteFactory::createOne(['community' => $community]);

        $response = $this->jsonClient($newcomer)->request(
            'POST',
            '/api/v1/invites/'.$invite->getToken().'/accept',
        );

        self::assertResponseIsSuccessful();
        self::assertSame('inv-accept', $response->toArray()['communityIdentifier']);

        // Now a member: can view the private community.
        $this->jsonClient($newcomer)->request('GET', '/api/v1/communities/inv-accept');
        self::assertResponseIsSuccessful();
    }

    public function testAcceptIsIdempotentForExistingMember(): void
    {
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('inv-idem')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        $invite = CommunityInviteFactory::createOne(['community' => $community]);

        $this->jsonClient($member)->request('POST', '/api/v1/invites/'.$invite->getToken().'/accept');

        self::assertResponseIsSuccessful();
    }

    public function testAcceptByExistingMemberDoesNotBurnUse(): void
    {
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('inv-noburn')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        $invite = CommunityInviteFactory::createOne(['community' => $community, 'maxUses' => 1]);

        $this->jsonClient($member)->request('POST', '/api/v1/invites/'.$invite->getToken().'/accept');
        self::assertResponseIsSuccessful();

        // The single use is still available for an actual newcomer.
        $newcomer = UserFactory::createOne();
        $this->jsonClient($newcomer)->request('POST', '/api/v1/invites/'.$invite->getToken().'/accept');
        self::assertResponseIsSuccessful();

        $em = self::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $fresh = $em->find(\App\Entity\CommunityInvite::class, $invite->getId());
        self::assertSame(1, $fresh?->getUseCount());
    }

    public function testPreviewReturnsCommunityName(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('inv-preview')->create();
        $invite = CommunityInviteFactory::createOne(['community' => $community]);

        $response = $this->jsonClient($user)->request('GET', '/api/v1/invites/'.$invite->getToken());

        self::assertResponseIsSuccessful();
        self::assertSame('inv-preview', $response->toArray()['communityIdentifier']);
    }

    public function testAcceptUnknownTokenReturns404(): void
    {
        $user = UserFactory::createOne();

        $this->jsonClient($user)->request('POST', '/api/v1/invites/deadbeefdeadbeef/accept');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAcceptExpiredInviteReturns410(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('inv-expired')->create();
        $invite = CommunityInviteFactory::createOne([
            'community' => $community,
            'expiresAt' => new \DateTimeImmutable('-1 hour'),
        ]);

        $this->jsonClient($user)->request('POST', '/api/v1/invites/'.$invite->getToken().'/accept');

        self::assertResponseStatusCodeSame(410);
    }

    public function testAcceptExhaustedInviteReturns410(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('inv-exhausted')->create();
        $invite = CommunityInviteFactory::createOne([
            'community' => $community,
            'maxUses' => 1,
            'useCount' => 1,
        ]);

        $this->jsonClient($user)->request('POST', '/api/v1/invites/'.$invite->getToken().'/accept');

        self::assertResponseStatusCodeSame(410);
    }

    public function testNonAdminCannotRevokeInvite(): void
    {
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('inv-rev-deny')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        $invite = CommunityInviteFactory::createOne(['community' => $community]);

        $this->jsonClient($member)->request(
            'DELETE',
            '/api/v1/communities/inv-rev-deny/invites/'.$invite->getId(),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousCannotRevokeInvite(): void
    {
        $community = CommunityFactory::new()->withIdentifier('inv-rev-anon')->create();
        $invite = CommunityInviteFactory::createOne(['community' => $community]);

        $this->jsonClient()->request(
            'DELETE',
            '/api/v1/communities/inv-rev-anon/invites/'.$invite->getId(),
        );

        self::assertResponseStatusCodeSame(401);
    }
}
