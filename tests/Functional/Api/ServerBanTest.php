<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Server bans apply app-wide but are issued from a community context (the
 * community recorded on the action is the place where the admin acted).
 * They ride on the same moderation endpoint as other actions; only ROLE_ADMIN
 * may issue or lift one.
 */
class ServerBanTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function modUrl(string $community, ?int $id = null): string
    {
        $url = '/api/v1/communities/'.$community.'/moderation';
        if (null !== $id) {
            $url .= '/'.$id;
        }

        return $url;
    }

    public function testGlobalAdminCanIssueServerBan(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('sb-issue')->create();
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $response = $this->jsonClient($admin)->request('POST', $this->modUrl('sb-issue'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'server_ban',
            'reason' => 'spam across communities',
        ]]);

        self::assertResponseStatusCodeSame(201);
        $data = $response->toArray();
        self::assertSame('server_ban', $data['type']);
        self::assertTrue($data['active']);
        self::assertSame('sb-issue', $data['communityIdentifier']);
    }

    public function testCommunityAdminCannotIssueServerBan(): void
    {
        $communityAdmin = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('sb-deny-cadmin')->create();
        CommunityMemberFactory::createAdminForCommunity($communityAdmin, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $this->jsonClient($communityAdmin)->request('POST', $this->modUrl('sb-deny-cadmin'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'server_ban',
        ]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testCommunityModeratorCannotIssueServerBan(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('sb-deny-cmod')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $this->jsonClient($mod)->request('POST', $this->modUrl('sb-deny-cmod'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'server_ban',
        ]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testCannotServerBanAnotherGlobalAdmin(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $otherAdmin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('sb-immune')->create();

        $this->jsonClient($admin)->request('POST', $this->modUrl('sb-immune'), ['json' => [
            'targetUserId' => $otherAdmin->getId(),
            'type' => 'server_ban',
        ]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testServerBannedUserCannotAuthenticate(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::new()->withPassword('secret123')->with(['email' => 'sb-banned@example.com'])->create();
        $community = CommunityFactory::new()->withIdentifier('sb-auth')->create();
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $this->jsonClient($admin)->request('POST', $this->modUrl('sb-auth'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'server_ban',
        ]]);
        self::assertResponseStatusCodeSame(201);

        static::createClient()->request('POST', '/auth', [
            'json' => ['email' => 'sb-banned@example.com', 'password' => 'secret123'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAdminCanLiftServerBan(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('sb-lift')->create();
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $response = $this->jsonClient($admin)->request('POST', $this->modUrl('sb-lift'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'server_ban',
        ]]);
        $actionId = $response->toArray()['id'];

        $this->jsonClient($admin)->request('DELETE', $this->modUrl('sb-lift', $actionId));
        self::assertResponseStatusCodeSame(204);
    }

    public function testCommunityAdminCannotLiftServerBan(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $communityAdmin = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('sb-lift-deny')->create();
        CommunityMemberFactory::createAdminForCommunity($communityAdmin, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $response = $this->jsonClient($admin)->request('POST', $this->modUrl('sb-lift-deny'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'server_ban',
        ]]);
        $actionId = $response->toArray()['id'];

        $this->jsonClient($communityAdmin)->request('DELETE', $this->modUrl('sb-lift-deny', $actionId));
        self::assertResponseStatusCodeSame(403);
    }
}
