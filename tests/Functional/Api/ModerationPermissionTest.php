<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\Channel\ChannelRole;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ModerationPermissionTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function modUrl(string $community, ?int $id = null): string
    {
        $url = '/api/v1/communities/'.$community.'/moderation';

        return null !== $id ? $url.'/'.$id : $url;
    }

    private function notesUrl(string $community, int $userId): string
    {
        return '/api/v1/communities/'.$community.'/users/'.$userId.'/notes';
    }

    public function testChannelModCannotWarn(): void
    {
        $channelMod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('chmod-no-warn')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'gen'])->create();
        CommunityMemberFactory::createForUserAndCommunity($channelMod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        ChannelMemberFactory::createForUserAndChannel($channelMod, $channel, ChannelRole::Moderator);

        $this->jsonClient($channelMod)->request('POST', $this->modUrl('chmod-no-warn'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'warn',
        ]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testChannelModCannotIssueCommunityWideTimeout(): void
    {
        $channelMod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('chmod-no-global-timeout')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'gen'])->create();
        CommunityMemberFactory::createForUserAndCommunity($channelMod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        ChannelMemberFactory::createForUserAndChannel($channelMod, $channel, ChannelRole::Moderator);

        $expiresAt = (new \DateTimeImmutable('+1 hour'))->format(\DateTimeInterface::ATOM);

        // No channelIdentifier = community-wide scope → must be denied
        $this->jsonClient($channelMod)->request('POST', $this->modUrl('chmod-no-global-timeout'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'timeout',
            'expiresAt' => $expiresAt,
        ]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testChannelModCannotTimeoutInDifferentChannel(): void
    {
        $channelMod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('chmod-wrong-channel')->create();
        $ownChannel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'own'])->create();
        $otherChannel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'other'])->create();
        CommunityMemberFactory::createForUserAndCommunity($channelMod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        ChannelMemberFactory::createForUserAndChannel($channelMod, $ownChannel, ChannelRole::Moderator);

        $expiresAt = (new \DateTimeImmutable('+1 hour'))->format(\DateTimeInterface::ATOM);

        $this->jsonClient($channelMod)->request('POST', $this->modUrl('chmod-wrong-channel'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'timeout',
            'expiresAt' => $expiresAt,
            'channelIdentifier' => $otherChannel->getIdentifier(),
        ]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testChannelModCannotLiftCommunityWideAction(): void
    {
        $globalMod = UserFactory::createOne();
        $channelMod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('chmod-no-lift-global')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'gen'])->create();
        CommunityMemberFactory::createModeratorForCommunity($globalMod, $community);
        CommunityMemberFactory::createForUserAndCommunity($channelMod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        ChannelMemberFactory::createForUserAndChannel($channelMod, $channel, ChannelRole::Moderator);

        $expiresAt = (new \DateTimeImmutable('+1 hour'))->format(\DateTimeInterface::ATOM);

        $response = $this->jsonClient($globalMod)->request('POST', $this->modUrl('chmod-no-lift-global'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'timeout',
            'expiresAt' => $expiresAt,
        ]]);
        $actionId = $response->toArray()['id'];

        // Channel mod tries to lift it → must be denied (not their channel scope)
        $this->jsonClient($channelMod)->request('DELETE', $this->modUrl('chmod-no-lift-global', $actionId));

        self::assertResponseStatusCodeSame(403);
    }

    public function testChannelModCannotLiftActionFromDifferentChannel(): void
    {
        $globalMod = UserFactory::createOne();
        $channelMod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('chmod-no-lift-other')->create();
        $ownChannel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'own'])->create();
        $otherChannel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'other'])->create();
        CommunityMemberFactory::createModeratorForCommunity($globalMod, $community);
        CommunityMemberFactory::createForUserAndCommunity($channelMod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        ChannelMemberFactory::createForUserAndChannel($channelMod, $ownChannel, ChannelRole::Moderator);

        $expiresAt = (new \DateTimeImmutable('+1 hour'))->format(\DateTimeInterface::ATOM);

        $response = $this->jsonClient($globalMod)->request('POST', $this->modUrl('chmod-no-lift-other'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'timeout',
            'expiresAt' => $expiresAt,
            'channelIdentifier' => $otherChannel->getIdentifier(),
        ]]);
        $actionId = $response->toArray()['id'];

        // Channel mod of ownChannel tries to lift it → must be denied
        $this->jsonClient($channelMod)->request('DELETE', $this->modUrl('chmod-no-lift-other', $actionId));

        self::assertResponseStatusCodeSame(403);
    }

    public function testChannelTimeoutBlocksOnlyThatChannel(): void
    {
        $globalMod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('channel-timeout-scope')->create();
        $timedChannel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'timed'])->create();
        $freeChannel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'free'])->create();
        CommunityMemberFactory::createModeratorForCommunity($globalMod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        ChannelMemberFactory::createForUserAndChannel($target, $timedChannel);
        ChannelMemberFactory::createForUserAndChannel($target, $freeChannel);

        $expiresAt = (new \DateTimeImmutable('+1 hour'))->format(\DateTimeInterface::ATOM);

        $this->jsonClient($globalMod)->request('POST', $this->modUrl('channel-timeout-scope'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'timeout',
            'expiresAt' => $expiresAt,
            'channelIdentifier' => 'timed',
        ]]);
        self::assertResponseStatusCodeSame(201);

        $this->jsonClient($target)->request('POST', '/api/v1/communities/channel-timeout-scope/channels/timed/messages', [
            'json' => ['text' => 'Hello?'],
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->jsonClient($target)->request('POST', '/api/v1/communities/channel-timeout-scope/channels/free/messages', [
            'json' => ['text' => 'Still here!'],
        ]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testModerationLogTypeFilterReturnOnlyMatchingType(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('log-type-filter')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $expiresAt = (new \DateTimeImmutable('+1 hour'))->format(\DateTimeInterface::ATOM);

        $this->jsonClient($mod)->request('POST', $this->modUrl('log-type-filter'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'warn',
        ]]);
        $this->jsonClient($mod)->request('POST', $this->modUrl('log-type-filter'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'timeout',
            'expiresAt' => $expiresAt,
        ]]);

        $response = $this->jsonClient($mod)->request('GET', $this->modUrl('log-type-filter').'?type=warn');

        self::assertResponseIsSuccessful();
        $members = $response->toArray()['hydra:member'];
        self::assertCount(1, $members);
        self::assertSame('warn', $members[0]['type']);
    }

    public function testModerationLogActiveFilterExcludesLiftedActions(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('log-active-filter')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $expiresAt = (new \DateTimeImmutable('+1 hour'))->format(\DateTimeInterface::ATOM);

        $response1 = $this->jsonClient($mod)->request('POST', $this->modUrl('log-active-filter'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'timeout',
            'expiresAt' => $expiresAt,
        ]]);
        $action1Id = $response1->toArray()['id'];

        $this->jsonClient($mod)->request('POST', $this->modUrl('log-active-filter'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'timeout',
            'expiresAt' => $expiresAt,
        ]]);

        $this->jsonClient($mod)->request('DELETE', $this->modUrl('log-active-filter', $action1Id));
        self::assertResponseStatusCodeSame(204);

        $response = $this->jsonClient($mod)->request('GET', $this->modUrl('log-active-filter').'?active=1');

        self::assertResponseIsSuccessful();
        $members = $response->toArray()['hydra:member'];
        self::assertCount(1, $members);
        self::assertTrue($members[0]['active']);
    }

    public function testModerationLogTargetUserIdFilterReturnOnlyThatUser(): void
    {
        $mod = UserFactory::createOne();
        $targetA = UserFactory::createOne();
        $targetB = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('log-user-filter')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($targetA, $community);
        CommunityMemberFactory::createForUserAndCommunity($targetB, $community);

        $this->jsonClient($mod)->request('POST', $this->modUrl('log-user-filter'), ['json' => [
            'targetUserId' => $targetA->getId(),
            'type' => 'warn',
        ]]);
        $this->jsonClient($mod)->request('POST', $this->modUrl('log-user-filter'), ['json' => [
            'targetUserId' => $targetB->getId(),
            'type' => 'warn',
        ]]);

        $response = $this->jsonClient($mod)->request(
            'GET',
            $this->modUrl('log-user-filter').'?targetUserId='.$targetA->getId(),
        );

        self::assertResponseIsSuccessful();
        $members = $response->toArray()['hydra:member'];
        self::assertCount(1, $members);
        self::assertSame($targetA->getId(), $members[0]['targetUser']['id']);
    }

    public function testRegularMemberCannotViewActiveModerationForOthers(): void
    {
        // Security regression: a plain member must NOT read another user's
        // warns/timeouts/bans. (Previously returned 200 — cross-community leak.)
        $member = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('active-mod-deny')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $this->jsonClient($member)->request(
            'GET',
            '/api/v1/communities/active-mod-deny/users/'.$target->getId().'/active-moderation',
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testUserCanViewOwnActiveModeration(): void
    {
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('active-mod-self')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);

        $this->jsonClient($member)->request(
            'GET',
            '/api/v1/communities/active-mod-self/users/'.$member->getId().'/active-moderation',
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testModeratorCanViewActiveModerationForOthers(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('active-mod-mod')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $this->jsonClient($mod)->request(
            'GET',
            '/api/v1/communities/active-mod-mod/users/'.$target->getId().'/active-moderation',
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testRegularMemberCannotReadModeratorNotesAboutThemselves(): void
    {
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('notes-self-deny')->create();
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $this->jsonClient($target)->request('GET', $this->notesUrl('notes-self-deny', $target->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testCommunityModeratorCannotUpdateCommunity(): void
    {
        $mod = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('mod-no-update-comm')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);

        $this->jsonClient($mod)->request('PATCH', '/api/v1/communities/mod-no-update-comm', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Hacked Name'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testCommunityModeratorCannotCreateChannel(): void
    {
        $mod = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('mod-no-create-ch')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);

        $this->jsonClient($mod)->request('POST', '/api/v1/channels', ['json' => [
            'name' => 'Sneaky Channel',
            'community' => '/api/v1/communities/mod-no-create-ch',
            'type' => 'text',
        ]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testCommunityModeratorCannotDeleteChannel(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $mod = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('mod-no-delete-ch')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'target-ch'])->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);

        $this->jsonClient($mod)->request('DELETE', '/api/v1/communities/mod-no-delete-ch/channels/target-ch');

        self::assertResponseStatusCodeSame(403);

        $this->jsonClient($admin)->request('GET', '/api/v1/communities/mod-no-delete-ch/channels/target-ch');
        self::assertResponseIsSuccessful();
    }

    public function testCommunityModeratorCannotChangeMemberRole(): void
    {
        $mod = UserFactory::createOne();
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('mod-no-role-change')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        $communityMember = CommunityMemberFactory::createForUserAndCommunity($member, $community);

        $this->jsonClient($mod)->request('PATCH', '/api/v1/communities/mod-no-role-change/members/'.$communityMember->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['role' => 'admin'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testCommunityAdminCannotDeleteCommunity(): void
    {
        $communityAdmin = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cadmin-no-delete')->create();
        CommunityMemberFactory::createAdminForCommunity($communityAdmin, $community);

        $this->jsonClient($communityAdmin)->request('DELETE', '/api/v1/communities/cadmin-no-delete');

        self::assertResponseStatusCodeSame(403);
    }

    public function testCommunityAdminCannotCreateCommunity(): void
    {
        $communityAdmin = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cadmin-no-create')->create();
        CommunityMemberFactory::createAdminForCommunity($communityAdmin, $community);

        $this->jsonClient($communityAdmin)->request('POST', '/api/v1/communities', ['json' => [
            'name' => 'Stolen Community',
            'identifier' => 'stolen',
        ]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testCommunityAdminCannotChangeUserGlobalRoles(): void
    {
        $communityAdmin = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cadmin-no-roles')->create();
        CommunityMemberFactory::createAdminForCommunity($communityAdmin, $community);

        $this->jsonClient($communityAdmin)->request('PATCH', '/api/v1/users/'.$target->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['roles' => ['ROLE_ADMIN']],
        ]);

        self::assertResponseStatusCodeSame(403);
    }
}
