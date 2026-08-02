<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\Message;
use App\Entity\User;
use App\Enum\ApiKey\ApiKeyScope;
use App\Enum\Message\MessageKind;
use App\Tests\Factory\ApiKeyFactory;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class PatScopeModerationAdminTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /**
     * @return array{community: Community, channel: Channel, author: User, message: Message}
     */
    private function seedChannelMessage(string $identifier): array
    {
        $author = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier($identifier)->create();
        $channel = ChannelFactory::new()->inCommunity($community)->create();
        CommunityMemberFactory::createForUserAndCommunity($author, $community);
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($author)->withText('offensive text')->with(['kind' => MessageKind::Standard])->create();

        return ['community' => $community, 'channel' => $channel, 'author' => $author, 'message' => $message];
    }

    private function withToken(string $token): Client
    {
        return static::createClient(defaultOptions: [
            'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/ld+json'],
        ]);
    }

    private function withTokenPlainJson(string $token): Client
    {
        return static::createClient(defaultOptions: [
            'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'],
        ]);
    }

    private function assertInsufficientScope(mixed $response, string $scope): void
    {
        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString($scope, $headers['www-authenticate'][0] ?? '');
    }

    public function testModerationWriteCanWarnUser(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('pat-mod-warn')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $issued = ApiKeyFactory::createWithToken($mod, ['scopes' => [ApiKeyScope::ModerationWrite->value]]);

        $this->withToken($issued['plainToken'])->request('POST', '/api/v1/communities/pat-mod-warn/moderation', ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'warn',
            'reason' => 'Spam',
        ]]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testModerationWriteCanTimeoutUser(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('pat-mod-timeout')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $issued = ApiKeyFactory::createWithToken($mod, ['scopes' => [ApiKeyScope::ModerationWrite->value]]);
        $expiresAt = (new \DateTimeImmutable('+1 hour'))->format(\DateTimeInterface::ATOM);

        $this->withToken($issued['plainToken'])->request('POST', '/api/v1/communities/pat-mod-timeout/moderation', ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'timeout',
            'reason' => 'Flooding',
            'expiresAt' => $expiresAt,
        ]]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testModerationWriteCanBanUser(): void
    {
        $admin = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('pat-mod-ban')->create();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::ModerationWrite->value]]);

        $this->withToken($issued['plainToken'])->request('POST', '/api/v1/communities/pat-mod-ban/moderation', ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'ban',
            'reason' => 'Repeated abuse',
        ]]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testModerationWriteAloneCannotCreateServerBan(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();
        CommunityFactory::new()->withIdentifier('pat-mod-serverban-wrong-scope')->create();
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::ModerationWrite->value]]);

        $response = $this->withToken($issued['plainToken'])->request('POST', '/api/v1/communities/pat-mod-serverban-wrong-scope/moderation', ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'server_ban',
            'reason' => 'Abuse',
        ]]);

        $this->assertInsufficientScope($response, 'admin');
    }

    public function testModerationWriteAndAdminScopeCanCreateServerBan(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();
        CommunityFactory::new()->withIdentifier('pat-mod-serverban-ok')->create();
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::ModerationWrite->value, ApiKeyScope::Admin->value]]);

        $this->withToken($issued['plainToken'])->request('POST', '/api/v1/communities/pat-mod-serverban-ok/moderation', ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'server_ban',
            'reason' => 'Abuse',
        ]]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testModerationWriteAloneCannotLiftServerBan(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();
        CommunityFactory::new()->withIdentifier('pat-mod-serverban-lift-wrong')->create();
        $fullIssued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::ModerationWrite->value, ApiKeyScope::Admin->value]]);
        $writeOnlyIssued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::ModerationWrite->value]]);

        $created = $this->withToken($fullIssued['plainToken'])->request('POST', '/api/v1/communities/pat-mod-serverban-lift-wrong/moderation', ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'server_ban',
            'reason' => 'Abuse',
        ]]);
        self::assertResponseStatusCodeSame(201);
        $actionId = $created->toArray()['id'];

        $response = $this->withToken($writeOnlyIssued['plainToken'])->request('DELETE', '/api/v1/communities/pat-mod-serverban-lift-wrong/moderation/'.$actionId);

        $this->assertInsufficientScope($response, 'admin');
    }

    public function testModerationWriteAndAdminScopeCanLiftServerBan(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();
        CommunityFactory::new()->withIdentifier('pat-mod-serverban-lift-ok')->create();
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::ModerationWrite->value, ApiKeyScope::Admin->value]]);

        $created = $this->withToken($issued['plainToken'])->request('POST', '/api/v1/communities/pat-mod-serverban-lift-ok/moderation', ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'server_ban',
            'reason' => 'Abuse',
        ]]);
        self::assertResponseStatusCodeSame(201);
        $actionId = $created->toArray()['id'];

        $this->withToken($issued['plainToken'])->request('DELETE', '/api/v1/communities/pat-mod-serverban-lift-ok/moderation/'.$actionId);

        self::assertResponseStatusCodeSame(204);
    }

    public function testModerationReadCanListAndGetActionWriteCanLift(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('pat-mod-log')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $writeIssued = ApiKeyFactory::createWithToken($mod, ['scopes' => [ApiKeyScope::ModerationWrite->value]]);
        $readIssued = ApiKeyFactory::createWithToken($mod, ['scopes' => [ApiKeyScope::ModerationRead->value]]);
        $expiresAt = (new \DateTimeImmutable('+1 hour'))->format(\DateTimeInterface::ATOM);

        $created = $this->withToken($writeIssued['plainToken'])->request('POST', '/api/v1/communities/pat-mod-log/moderation', ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'timeout',
            'reason' => 'Flooding',
            'expiresAt' => $expiresAt,
        ]]);
        self::assertResponseStatusCodeSame(201);
        $actionId = $created->toArray()['id'];

        $this->withToken($readIssued['plainToken'])->request('GET', '/api/v1/communities/pat-mod-log/moderation');
        self::assertResponseStatusCodeSame(200);

        $this->withToken($readIssued['plainToken'])->request('GET', '/api/v1/communities/pat-mod-log/moderation/'.$actionId);
        self::assertResponseStatusCodeSame(200);

        $this->withToken($readIssued['plainToken'])->request('GET', '/api/v1/communities/pat-mod-log/users/'.$target->getId().'/active-moderation');
        self::assertResponseStatusCodeSame(200);

        $this->withToken($writeIssued['plainToken'])->request('DELETE', '/api/v1/communities/pat-mod-log/moderation/'.$actionId);
        self::assertResponseStatusCodeSame(204);
    }

    public function testModerationWriteCanCreateAndPatchNotesReadCanList(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('pat-mod-notes')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $writeIssued = ApiKeyFactory::createWithToken($mod, ['scopes' => [ApiKeyScope::ModerationWrite->value]]);
        $readIssued = ApiKeyFactory::createWithToken($mod, ['scopes' => [ApiKeyScope::ModerationRead->value]]);

        $created = $this->withToken($writeIssued['plainToken'])->request(
            'POST',
            '/api/v1/communities/pat-mod-notes/users/'.$target->getId().'/notes',
            ['json' => ['content' => 'Watch this one.']],
        );
        self::assertResponseStatusCodeSame(201);
        $noteId = $created->toArray()['id'];

        $this->withToken($readIssued['plainToken'])->request('GET', '/api/v1/communities/pat-mod-notes/users/'.$target->getId().'/notes');
        self::assertResponseStatusCodeSame(200);

        $this->withToken($writeIssued['plainToken'])->request('PATCH', '/api/v1/communities/pat-mod-notes/notes/'.$noteId, [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['content' => 'Updated.'],
        ]);
        self::assertResponseStatusCodeSame(200);

        $this->withToken($writeIssued['plainToken'])->request('DELETE', '/api/v1/communities/pat-mod-notes/notes/'.$noteId);
        self::assertResponseStatusCodeSame(204);
    }

    public function testModerationWriteCanCreateAndPatchReportReadCanList(): void
    {
        $seed = $this->seedChannelMessage('pat-mod-report');
        $reporter = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($reporter, $seed['community']);
        $mod = UserFactory::createOne();
        CommunityMemberFactory::createModeratorForCommunity($mod, $seed['community']);
        $reporterIssued = ApiKeyFactory::createWithToken($reporter, ['scopes' => [ApiKeyScope::ModerationWrite->value]]);
        $modWriteIssued = ApiKeyFactory::createWithToken($mod, ['scopes' => [ApiKeyScope::ModerationWrite->value]]);
        $modReadIssued = ApiKeyFactory::createWithToken($mod, ['scopes' => [ApiKeyScope::ModerationRead->value]]);

        $created = $this->withToken($reporterIssued['plainToken'])->request('POST', '/api/v1/reports', ['json' => [
            'messageId' => $seed['message']->getId(),
            'category' => 'harassment',
        ]]);
        self::assertResponseStatusCodeSame(201);
        $reportId = $created->toArray()['id'];

        $this->withToken($modReadIssued['plainToken'])->request('GET', '/api/v1/communities/pat-mod-report/reports');
        self::assertResponseStatusCodeSame(200);

        $this->withToken($modWriteIssued['plainToken'])->request('PATCH', '/api/v1/reports/'.$reportId, [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['status' => 'resolved', 'resolutionNote' => 'Handled'],
        ]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testModerationWriteCanFileAndDecideAppealReadCanList(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('pat-mod-appeal')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $modWriteIssued = ApiKeyFactory::createWithToken($mod, ['scopes' => [ApiKeyScope::ModerationWrite->value]]);
        $modReadIssued = ApiKeyFactory::createWithToken($mod, ['scopes' => [ApiKeyScope::ModerationRead->value]]);
        $targetIssued = ApiKeyFactory::createWithToken($target, ['scopes' => [ApiKeyScope::ModerationWrite->value]]);

        $created = $this->withToken($modWriteIssued['plainToken'])->request('POST', '/api/v1/communities/pat-mod-appeal/moderation', ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'warn',
            'reason' => 'Spam',
        ]]);
        self::assertResponseStatusCodeSame(201);
        $actionId = $created->toArray()['id'];

        $appeal = $this->withToken($targetIssued['plainToken'])->request('POST', '/api/v1/moderation-actions/'.$actionId.'/appeals', ['json' => [
            'reason' => 'I did not post spam.',
        ]]);
        self::assertResponseStatusCodeSame(201);
        $appealId = $appeal->toArray()['id'];

        $this->withToken($modReadIssued['plainToken'])->request('GET', '/api/v1/communities/pat-mod-appeal/appeals');
        self::assertResponseStatusCodeSame(200);

        $this->withToken($modWriteIssued['plainToken'])->request('PATCH', '/api/v1/appeals/'.$appealId, [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['status' => 'upheld', 'resolutionNote' => 'Warning stands.'],
        ]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testModerationReadAloneCannotWarnUser(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('pat-mod-read-only')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $issued = ApiKeyFactory::createWithToken($mod, ['scopes' => [ApiKeyScope::ModerationRead->value]]);

        $response = $this->withToken($issued['plainToken'])->request('POST', '/api/v1/communities/pat-mod-read-only/moderation', ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'warn',
        ]]);

        $this->assertInsufficientScope($response, 'moderation:write');
    }

    public function testWrongScopeRejectedForWarn(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('pat-mod-wrong-scope')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $issued = ApiKeyFactory::createWithToken($mod, ['scopes' => [ApiKeyScope::ProfileWrite->value]]);

        $response = $this->withToken($issued['plainToken'])->request('POST', '/api/v1/communities/pat-mod-wrong-scope/moderation', ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'warn',
        ]]);

        $this->assertInsufficientScope($response, 'moderation:write');
    }

    public function testCommunitiesScopeCannotFileReport(): void
    {
        $seed = $this->seedChannelMessage('pat-mod-domain-report');
        $reporter = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($reporter, $seed['community']);
        $issued = ApiKeyFactory::createWithToken($reporter, ['scopes' => [ApiKeyScope::CommunitiesWrite->value]]);

        $response = $this->withToken($issued['plainToken'])->request('POST', '/api/v1/reports', ['json' => [
            'messageId' => $seed['message']->getId(),
            'category' => 'spam',
        ]]);

        $this->assertInsufficientScope($response, 'moderation:write');
    }

    public function testAdminScopedCanListAdminUsers(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::Admin->value]]);

        $this->withTokenPlainJson($issued['plainToken'])->request('GET', '/api/v1/admin/users');

        self::assertResponseStatusCodeSame(200);
    }

    public function testAdminScopedCanReachServerConfig(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::Admin->value]]);

        $this->withTokenPlainJson($issued['plainToken'])->request('GET', '/api/v1/admin/server-config');

        self::assertResponseStatusCodeSame(200);
    }

    public function testAdminScopedCanReachWebhooks(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::Admin->value]]);

        $this->withTokenPlainJson($issued['plainToken'])->request('GET', '/api/v1/admin/webhooks');

        self::assertResponseStatusCodeSame(200);
    }

    public function testNonAdminScopedDeniedAdminUsers(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::ProfileRead->value]]);

        $response = $this->withTokenPlainJson($issued['plainToken'])->request('GET', '/api/v1/admin/users');

        $this->assertInsufficientScope($response, 'admin');
    }

    public function testNonAdminScopedDeniedServerConfig(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::ProfileRead->value]]);

        $response = $this->withTokenPlainJson($issued['plainToken'])->request('GET', '/api/v1/admin/server-config');

        $this->assertInsufficientScope($response, 'admin');
    }

    public function testNonAdminScopedDeniedWebhooks(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::ProfileRead->value]]);

        $response = $this->withTokenPlainJson($issued['plainToken'])->request('GET', '/api/v1/admin/webhooks');

        $this->assertInsufficientScope($response, 'admin');
    }

    public function testAdminScopedStillDeniedOnboardingComplete(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $issued = ApiKeyFactory::createWithToken($admin, ['scopes' => [ApiKeyScope::Admin->value]]);

        $this->withTokenPlainJson($issued['plainToken'])->request('POST', '/api/v1/admin/server-config/onboarding/complete');

        self::assertResponseStatusCodeSame(403);
    }
}
