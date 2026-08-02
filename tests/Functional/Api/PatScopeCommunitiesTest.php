<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\ChannelSection;
use App\Entity\Community;
use App\Entity\User;
use App\Enum\ApiKey\ApiKeyScope;
use App\Tests\Factory\ApiKeyFactory;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\UserGroupFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class PatScopeCommunitiesTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /** @return array{User, Community} a plain member of a public community */
    private function createCommunityWithMember(string $identifier): array
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier($identifier)->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        return [$user, $community];
    }

    /** @return array{User, Community} a community admin of a public community */
    private function createCommunityWithAdmin(string $identifier): array
    {
        $admin = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier($identifier)->create();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);

        return [$admin, $community];
    }

    private function createSection(Community $community, string $name = 'General'): ChannelSection
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $section = new ChannelSection();
        $section->setName($name);
        $section->setCommunity($community);
        $em->persist($section);
        $em->flush();

        return $section;
    }

    private function withToken(string $token): Client
    {
        return static::createClient(defaultOptions: [
            'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/ld+json'],
        ]);
    }

    private function assertInsufficientScope(mixed $response, string $scope): void
    {
        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString($scope, $headers['www-authenticate'][0] ?? '');
    }

    // --- communities:read ---

    public function testCommunitiesReadCanListCommunities(): void
    {
        [$user] = $this->createCommunityWithMember('pat-com-list');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::CommunitiesRead->value]]);

        $this->withToken($issued['plainToken'])->request('GET', '/api/v1/communities');

        self::assertResponseStatusCodeSame(200);
    }

    public function testCommunitiesReadCanGetCommunity(): void
    {
        [$user, $community] = $this->createCommunityWithMember('pat-com-get');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::CommunitiesRead->value]]);

        $this->withToken($issued['plainToken'])->request('GET', '/api/v1/communities/'.$community->getIdentifier());

        self::assertResponseStatusCodeSame(200);
    }

    public function testCommunitiesReadCanGetChannel(): void
    {
        [$user, $community] = $this->createCommunityWithMember('pat-com-channel-get');
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::CommunitiesRead->value]]);

        $this->withToken($issued['plainToken'])->request(
            'GET',
            '/api/v1/communities/'.$community->getIdentifier().'/channels/'.$channel->getIdentifier(),
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testCommunitiesReadCanListMembers(): void
    {
        [$user, $community] = $this->createCommunityWithMember('pat-com-members');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::CommunitiesRead->value]]);

        $this->withToken($issued['plainToken'])->request('GET', '/api/v1/communities/'.$community->getIdentifier().'/members');

        self::assertResponseStatusCodeSame(200);
    }

    public function testCommunitiesReadCanGetSection(): void
    {
        [$user, $community] = $this->createCommunityWithMember('pat-com-section');
        $section = $this->createSection($community);
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::CommunitiesRead->value]]);

        $this->withToken($issued['plainToken'])->request('GET', '/api/v1/communities/pat-com-section/sections/'.$section->getId());

        self::assertResponseStatusCodeSame(200);
    }

    public function testCommunitiesReadCanListGroups(): void
    {
        [$user, $community] = $this->createCommunityWithMember('pat-com-groups');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::CommunitiesRead->value]]);

        $this->withToken($issued['plainToken'])->request('GET', '/api/v1/communities/'.$community->getIdentifier().'/groups');

        self::assertResponseStatusCodeSame(200);
    }

    public function testCommunitiesReadCanListEmojis(): void
    {
        [$user, $community] = $this->createCommunityWithMember('pat-com-emojis');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::CommunitiesRead->value]]);

        $this->withToken($issued['plainToken'])->request('GET', '/api/v1/communities/'.$community->getIdentifier().'/emojis');

        self::assertResponseStatusCodeSame(200);
    }

    public function testCommunitiesReadCanGetPresenceSummary(): void
    {
        [$user, $community] = $this->createCommunityWithMember('pat-com-presence');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::CommunitiesRead->value]]);

        $this->withToken($issued['plainToken'])->request(
            'GET',
            '/api/v1/communities/'.$community->getIdentifier().'/presence/summary',
        );

        self::assertResponseStatusCodeSame(200);
    }

    // --- communities:write ---

    public function testAdminScopeCanCreateCommunity(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::Admin->value]]);

        $this->withToken($issued['plainToken'])->request('POST', '/api/v1/communities', [
            'json' => ['name' => 'Pat Community'],
        ]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testCommunitiesWriteCanJoinCommunity(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('pat-com-join')->create();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::CommunitiesWrite->value]]);

        $this->withToken($issued['plainToken'])->request('POST', '/api/v1/communities/'.$community->getIdentifier().'/members');

        self::assertResponseStatusCodeSame(204);
    }

    public function testCommunitiesWriteCanCreateChannel(): void
    {
        [$admin, $community] = $this->createCommunityWithAdmin('pat-com-channel-create');
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::CommunitiesWrite->value]]);

        $this->withToken($issued['plainToken'])->request('POST', '/api/v1/channels', [
            'json' => [
                'name' => 'news',
                'identifier' => 'news',
                'type' => 'text',
                'community' => '/api/v1/communities/'.$community->getIdentifier(),
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testCommunitiesWriteCanPatchChannel(): void
    {
        [$admin, $community] = $this->createCommunityWithAdmin('pat-com-channel-patch');
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general', 'name' => 'Old'])->create();
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::CommunitiesWrite->value]]);

        $this->withToken($issued['plainToken'])->request(
            'PATCH',
            '/api/v1/communities/'.$community->getIdentifier().'/channels/'.$channel->getIdentifier(),
            [
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
                'json' => ['name' => 'New'],
            ],
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testCommunitiesWriteCanCreateGroup(): void
    {
        [$admin, $community] = $this->createCommunityWithAdmin('pat-com-group-create');
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::CommunitiesWrite->value]]);

        $this->withToken($issued['plainToken'])->request('POST', '/api/v1/communities/'.$community->getIdentifier().'/groups', [
            'json' => ['name' => 'Moderators'],
        ]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testCommunitiesWriteCanAddGroupMember(): void
    {
        [$admin, $community] = $this->createCommunityWithAdmin('pat-com-group-add-member');
        $target = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $group = UserGroupFactory::createInCommunity($community);
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::CommunitiesWrite->value]]);

        $this->withToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/communities/'.$community->getIdentifier().'/groups/'.$group->getIdentifier().'/members',
            ['json' => ['userId' => $target->getId()]],
        );

        self::assertResponseStatusCodeSame(201);
    }

    public function testCommunitiesWriteCanCreateInvite(): void
    {
        [$admin, $community] = $this->createCommunityWithAdmin('pat-com-invite-create');
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::CommunitiesWrite->value]]);

        $this->withToken($issued['plainToken'])->request('POST', '/api/v1/communities/'.$community->getIdentifier().'/invites', [
            'json' => ['maxUses' => 5],
        ]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testCommunitiesWriteCanCreateSection(): void
    {
        [$admin, $community] = $this->createCommunityWithAdmin('pat-com-section-create');
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::CommunitiesWrite->value]]);

        $this->withToken($issued['plainToken'])->request('POST', '/api/v1/communities/'.$community->getIdentifier().'/sections', [
            'json' => ['name' => 'Announcements'],
        ]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testCommunitiesWriteCanPinCommunity(): void
    {
        [$user, $community] = $this->createCommunityWithMember('pat-com-pin');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::CommunitiesWrite->value]]);

        $this->withToken($issued['plainToken'])->request('POST', '/api/v1/me/pinned-communities', [
            'json' => ['communityId' => $community->getId()],
        ]);

        self::assertResponseStatusCodeSame(201);
    }

    // --- wrong-scope / cross-domain ---

    public function testCommunitiesReadCannotCreateCommunity(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::CommunitiesRead->value]]);

        $response = $this->withToken($issued['plainToken'])->request('POST', '/api/v1/communities', [
            'json' => ['name' => 'Should Not Exist'],
        ]);

        $this->assertInsufficientScope($response, 'admin');
    }

    public function testCommunitiesWriteAloneCannotCreateCommunity(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::CommunitiesWrite->value]]);

        $response = $this->withToken($issued['plainToken'])->request('POST', '/api/v1/communities', [
            'json' => ['name' => 'Should Not Exist Either'],
        ]);

        $this->assertInsufficientScope($response, 'admin');
    }

    public function testWrongScopeRejectedForJoinCommunity(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('pat-com-join-wrong')->create();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ProfileWrite->value]]);

        $response = $this->withToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/communities/'.$community->getIdentifier().'/members',
        );

        $this->assertInsufficientScope($response, 'communities:write');
    }

    public function testCommunitiesWriteAloneCannotDeleteCommunity(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('pat-com-delete-wrong-scope')->create();
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::CommunitiesWrite->value]]);

        $response = $this->withToken($issued['plainToken'])->request('DELETE', '/api/v1/communities/'.$community->getIdentifier());

        $this->assertInsufficientScope($response, 'admin');
    }

    public function testAdminScopeCanDeleteCommunity(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('pat-com-delete-admin')->create();
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::Admin->value]]);

        $this->withToken($issued['plainToken'])->request('DELETE', '/api/v1/communities/'.$community->getIdentifier());

        self::assertResponseStatusCodeSame(204);
    }

    public function testMessagesScopeCannotCreateChannel(): void
    {
        [$admin, $community] = $this->createCommunityWithAdmin('pat-com-domain-channel');
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::MessagesRead->value, ApiKeyScope::MessagesWrite->value]]);

        $response = $this->withToken($issued['plainToken'])->request('POST', '/api/v1/channels', [
            'json' => [
                'name' => 'news',
                'identifier' => 'news',
                'type' => 'text',
                'community' => '/api/v1/communities/'.$community->getIdentifier(),
            ],
        ]);

        $this->assertInsufficientScope($response, 'communities:write');
    }
}
