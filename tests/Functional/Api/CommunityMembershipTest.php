<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\Channel\ChannelRole;
use App\Enum\Community\CommunityRole;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class CommunityMembershipTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testMemberSeesRoleHasMembershipAndChannelRoles(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('membership-member')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel, ChannelRole::Moderator);

        $response = $this->plainJsonClient($user)->request('GET', '/api/v1/communities/membership-member/membership');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertSame('member', $data['role']);
        self::assertTrue($data['hasMembership']);
        self::assertSame('moderator', $data['channelRoles'][(string) $channel->getId()]);
    }

    public function testNonMemberOfPublicCommunityGetsNullRoleAndEmptyChannelRoles(): void
    {
        $stranger = UserFactory::createOne();
        CommunityFactory::new()->withIdentifier('membership-public')->create();

        $response = $this->plainJsonClient($stranger)->request('GET', '/api/v1/communities/membership-public/membership');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertNull($data['role']);
        self::assertFalse($data['hasMembership']);
        self::assertSame([], $data['channelRoles']);
    }

    public function testNonMemberOfPrivateCommunityGetsNotFound(): void
    {
        $stranger = UserFactory::createOne();
        CommunityFactory::new()->private()->withIdentifier('membership-private')->create();

        $this->plainJsonClient($stranger)->request('GET', '/api/v1/communities/membership-private/membership');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousGetsUnauthorized(): void
    {
        CommunityFactory::new()->withIdentifier('membership-anon')->create();

        $this->plainJsonClient()->request('GET', '/api/v1/communities/membership-anon/membership');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousOnPrivateCommunityGetsNotFound(): void
    {
        // Existence-hiding wins over 401: the provider's voter-mapped 404 fires
        // before the operation's ROLE_USER check would produce a 401.
        CommunityFactory::new()->private()->withIdentifier('membership-anon-private')->create();

        $this->plainJsonClient()->request('GET', '/api/v1/communities/membership-anon-private/membership');

        self::assertResponseStatusCodeSame(404);
    }

    public function testGlobalAdminWithoutRowGetsNoBypass(): void
    {
        $admin = UserFactory::new()->admin()->create();
        CommunityFactory::new()->private()->withIdentifier('membership-admin')->create();

        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/communities/membership-admin/membership');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertNull($data['role']);
        self::assertFalse($data['hasMembership']);
    }

    public function testBulkEndpointListsExactlyCallersRows(): void
    {
        $user = UserFactory::createOne();
        $communityA = CommunityFactory::new()->withIdentifier('membership-bulk-a')->create();
        $communityB = CommunityFactory::new()->withIdentifier('membership-bulk-b')->create();
        CommunityFactory::new()->withIdentifier('membership-bulk-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $communityA);
        CommunityMemberFactory::createOne(['user' => $user, 'community' => $communityB, 'role' => CommunityRole::Admin]);

        $response = $this->plainJsonClient($user)->request('GET', '/api/v1/me/community-memberships');

        self::assertResponseIsSuccessful();
        $rows = $response->toArray();
        self::assertCount(2, $rows);

        $byIdentifier = [];
        foreach ($rows as $row) {
            $byIdentifier[$row['communityIdentifier']] = $row;
        }

        self::assertSame('member', $byIdentifier['membership-bulk-a']['role']);
        self::assertSame($communityA->getId(), $byIdentifier['membership-bulk-a']['communityId']);
        self::assertSame('admin', $byIdentifier['membership-bulk-b']['role']);
        self::assertArrayNotHasKey('membership-bulk-c', $byIdentifier);
    }

    public function testBulkEndpointRequiresAuthentication(): void
    {
        $this->plainJsonClient()->request('GET', '/api/v1/me/community-memberships');

        self::assertResponseStatusCodeSame(401);
    }
}
