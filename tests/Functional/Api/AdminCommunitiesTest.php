<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\AdminAuditLog;
use App\Entity\Community;
use App\Entity\CommunityMember;
use App\Enum\Admin\AdminAuditAction;
use App\Enum\Community\CommunityRole;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class AdminCommunitiesTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testListReturns403ForNonAdmin(): void
    {
        $user = UserFactory::createOne();

        $this->plainJsonClient($user)->request('GET', '/api/v1/admin/communities');

        self::assertResponseStatusCodeSame(403);
    }

    public function testListReturns401ForAnonymous(): void
    {
        static::createClient()->request('GET', '/api/v1/admin/communities', [
            'headers' => ['Accept' => 'application/json'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testListReturnsRowsWithStats(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('acme')->create();
        CommunityMemberFactory::createAdminForCommunity(UserFactory::createOne(), $community);

        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/communities');

        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertArrayHasKey('rows', $body);
        $identifiers = array_column($body['rows'], 'identifier');
        self::assertContains('acme', $identifiers);
        $row = $body['rows'][array_search('acme', $identifiers, true)];
        self::assertArrayHasKey('memberCount', $row);
        self::assertArrayHasKey('messageCount', $row);
        self::assertSame(1, $row['memberCount']);
    }

    public function testListSearchFilters(): void
    {
        $admin = UserFactory::new()->admin()->create();
        CommunityFactory::new()->withIdentifier('alpha')->create();
        CommunityFactory::new()->withIdentifier('bravo')->create();

        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/communities?search=alph');

        self::assertResponseStatusCodeSame(200);
        $identifiers = array_column($response->toArray()['rows'], 'identifier');
        self::assertContains('alpha', $identifiers);
        self::assertNotContains('bravo', $identifiers);
    }

    public function testListPaginatesAndSortsByName(): void
    {
        $admin = UserFactory::new()->admin()->create();
        CommunityFactory::new()->withIdentifier('sort-c')->create(['name' => 'Charlie']);
        CommunityFactory::new()->withIdentifier('sort-a')->create(['name' => 'Alpha']);
        CommunityFactory::new()->withIdentifier('sort-b')->create(['name' => 'Bravo']);

        $body = $this->plainJsonClient($admin)
            ->request('GET', '/api/v1/admin/communities?sort=name&dir=ASC&perPage=2')
            ->toArray();

        self::assertSame(3, $body['total']);
        self::assertSame(2, $body['perPage']);
        self::assertSame(['Alpha', 'Bravo'], array_column($body['rows'], 'name'));

        $page2 = $this->plainJsonClient($admin)
            ->request('GET', '/api/v1/admin/communities?sort=name&dir=ASC&perPage=2&page=2')
            ->toArray();

        self::assertSame(['Charlie'], array_column($page2['rows'], 'name'));
    }

    public function testDeleteRemovesCommunityAndRecordsAudit(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('doomed')->create();

        $this->plainJsonClient($admin)->request('DELETE', '/api/v1/admin/communities/doomed');

        self::assertResponseStatusCodeSame(204);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        self::assertNull($em->getRepository(Community::class)->findOneBy(['identifier' => 'doomed']));
        $audit = $em->getRepository(AdminAuditLog::class)->findOneBy(['action' => AdminAuditAction::CommunityDelete]);
        self::assertNotNull($audit);
        self::assertSame('doomed', $audit->getPayload()['identifier'] ?? null);
    }

    public function testTransferPromotesMemberAndDemotesExistingAdmin(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('transferme')->create();
        $oldAdmin = UserFactory::createOne();
        $newAdmin = UserFactory::createOne();
        CommunityMemberFactory::createAdminForCommunity($oldAdmin, $community);
        CommunityMemberFactory::createForUserAndCommunity($newAdmin, $community);

        $response = $this->plainJsonClient($admin)->request('POST', '/api/v1/admin/communities/transferme/transfer', [
            'json' => ['newAdminUserId' => $newAdmin->getId(), 'demoteOthers' => true],
        ]);

        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertTrue($body['ok']);
        self::assertSame(1, $body['demotedCount']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $repo = $em->getRepository(CommunityMember::class);
        $newRow = $repo->findOneBy(['user' => $newAdmin->getId()]);
        $oldRow = $repo->findOneBy(['user' => $oldAdmin->getId()]);
        self::assertSame(CommunityRole::Admin, $newRow?->getRole());
        self::assertSame(CommunityRole::Member, $oldRow?->getRole());
    }

    public function testTransferWithoutDemoteKeepsExistingAdmin(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('keepadmins')->create();
        $oldAdmin = UserFactory::createOne();
        $newAdmin = UserFactory::createOne();
        CommunityMemberFactory::createAdminForCommunity($oldAdmin, $community);
        CommunityMemberFactory::createForUserAndCommunity($newAdmin, $community);

        $response = $this->plainJsonClient($admin)->request('POST', '/api/v1/admin/communities/keepadmins/transfer', [
            'json' => ['newAdminUserId' => $newAdmin->getId()],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $response->toArray()['demotedCount']);
    }

    public function testTransferToNonMemberFails(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('nomember')->create();
        $outsider = UserFactory::createOne();

        $this->plainJsonClient($admin)->request('POST', '/api/v1/admin/communities/nomember/transfer', [
            'json' => ['newAdminUserId' => $outsider->getId()],
        ]);

        self::assertResponseStatusCodeSame(409);
    }

    public function testTransferToUnknownUserReturns404(): void
    {
        $admin = UserFactory::new()->admin()->create();
        CommunityFactory::new()->withIdentifier('ghost')->create();

        $this->plainJsonClient($admin)->request('POST', '/api/v1/admin/communities/ghost/transfer', [
            'json' => ['newAdminUserId' => 999999],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testTransferMissingFieldReturns422(): void
    {
        $admin = UserFactory::new()->admin()->create();
        CommunityFactory::new()->withIdentifier('badbody')->create();

        $this->plainJsonClient($admin)->request('POST', '/api/v1/admin/communities/badbody/transfer', [
            'json' => ['demoteOthers' => true],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testTransferReturns403ForNonAdmin(): void
    {
        $user = UserFactory::createOne();
        CommunityFactory::new()->withIdentifier('guarded')->create();

        $this->plainJsonClient($user)->request('POST', '/api/v1/admin/communities/guarded/transfer', [
            'json' => ['newAdminUserId' => 1],
        ]);

        self::assertResponseStatusCodeSame(403);
    }
}
