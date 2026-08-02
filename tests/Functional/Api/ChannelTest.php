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

class ChannelTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testChannelMemberCanGetPublicChannel(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c1')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);

        $this->jsonClient($user)->request('GET', '/api/v1/communities/c1/channels/general');

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['identifier' => 'general']);
    }

    public function testNonChannelMemberCanGetPublicChannel(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c2')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'public-ch'])->create();

        $this->jsonClient($user)->request('GET', '/api/v1/communities/c2/channels/public-ch');

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['identifier' => 'public-ch']);
    }

    public function testNonMemberCannotGetPrivateChannel(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c3')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'secret'])->create();

        $this->jsonClient($user)->request('GET', '/api/v1/communities/c3/channels/secret');

        self::assertResponseStatusCodeSame(404);
    }

    public function testPrivateChannelMemberCanGetChannel(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c3')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'members-only'])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);

        $this->jsonClient($user)->request('GET', '/api/v1/communities/c3/channels/members-only');

        self::assertResponseIsSuccessful();
    }

    public function testAdminCanCreateChannel(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('c-col')->create();

        $this->jsonClient($admin)->request('POST', '/api/v1/channels', ['json' => [
            'name' => 'news',
            'identifier' => 'news',
            'type' => 'text',
            'community' => '/api/v1/communities/c-col',
        ]]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testRegularUserCannotCreateChannel(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c-user')->create();

        $this->jsonClient($user)->request('POST', '/api/v1/channels', ['json' => [
            'name' => 'hacked',
            'identifier' => 'hacked',
            'type' => 'text',
            'community' => '/api/v1/communities/c-user',
        ]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateChannelWithBlankNameReturns422(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('c-blank')->create();

        $this->jsonClient($admin)->request('POST', '/api/v1/channels', ['json' => [
            'name' => '',
            'type' => 'text',
            'community' => '/api/v1/communities/c-blank',
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateChannelWithNameTooLongReturns422(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('c-long')->create();

        $this->jsonClient($admin)->request('POST', '/api/v1/channels', ['json' => [
            'name' => str_repeat('a', 256),
            'type' => 'text',
            'community' => '/api/v1/communities/c-long',
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateChannelWithInvalidRoleReturns422(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c-role-inv')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'ch-role-inv'])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);

        $this->jsonClient($admin)->request('PATCH', '/api/v1/communities/c-role-inv/channels/ch-role-inv/members/'.$user->getId().'/role', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['role' => 'superadmin'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testUpdateMemberRoleWithEmptyBodyReturns422(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $mod = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c-role-empty')->create();
        CommunityMemberFactory::createForUserAndCommunity($mod, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'ch-role-empty'])->create();
        ChannelMemberFactory::createForUserAndChannel($mod, $channel, ChannelRole::Moderator);

        // An empty PATCH must not silently demote the moderator to member.
        $this->jsonClient($admin)->request('PATCH', '/api/v1/communities/c-role-empty/channels/ch-role-empty/members/'.$mod->getId().'/role', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => [],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAdminCanAddMemberToPrivateChannel(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c-add')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'priv-ch'])->create();

        $this->jsonClient($admin)->request('POST', '/api/v1/communities/c-add/channels/priv-ch/members', [
            'json' => ['userId' => $user->getId()],
        ]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testAddMemberFailsWhenUserNotInCommunity(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c-outsider')->create();
        ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'ch-out'])->create();

        $this->jsonClient($admin)->request('POST', '/api/v1/communities/c-outsider/channels/ch-out/members', [
            'json' => ['userId' => $outsider->getId()],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testUserCanRemoveThemselves(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c-leave')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'ch-leave'])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);

        $this->jsonClient($user)->request('DELETE', '/api/v1/communities/c-leave/channels/ch-leave/members/'.$user->getId());

        self::assertResponseStatusCodeSame(204);
    }

    public function testRegularUserCannotRemoveModerator(): void
    {
        $user = UserFactory::createOne();
        $moderatorUser = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c-mod-rm')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        CommunityMemberFactory::createForUserAndCommunity($moderatorUser, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'ch-mod'])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);
        ChannelMemberFactory::createForUserAndChannel($moderatorUser, $channel, ChannelRole::Moderator);

        $this->jsonClient($user)->request('DELETE', '/api/v1/communities/c-mod-rm/channels/ch-mod/members/'.$moderatorUser->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanUpdateChannel(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('c-patch')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ch-patch', 'name' => 'Old Name'])->create();

        $this->jsonClient($admin)->request('PATCH', '/api/v1/communities/c-patch/channels/ch-patch', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'New Name'],
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testRegularUserCannotUpdateChannel(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c-patch-deny')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ch-deny'])->create();

        $this->jsonClient($user)->request('PATCH', '/api/v1/communities/c-patch-deny/channels/ch-deny', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Hacked'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testChannelTypeCannotBeChangedOnUpdate(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('c-type')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ch-type'])->create();

        $this->jsonClient($admin)->request('PATCH', '/api/v1/communities/c-type/channels/ch-type', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['type' => 'audio'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['type' => 'text']);
    }

    public function testAdminCanMoveChannelToAnotherSectionInSameCommunity(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('c-move')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ch-move'])->create();

        $section = $this->jsonClient($admin)->request('POST', '/api/v1/communities/c-move/sections', ['json' => [
            'name' => 'Target',
        ]])->toArray();

        $this->jsonClient($admin)->request('PATCH', '/api/v1/communities/c-move/channels/ch-move', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['section' => $section['@id']],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['section' => ['id' => $section['id']]]);
    }

    public function testMovingChannelToSectionInAnotherCommunityReturns422(): void
    {
        $admin = UserFactory::new()->admin()->create();
        CommunityFactory::new()->withIdentifier('c-move-a')->create();
        $other = CommunityFactory::new()->withIdentifier('c-move-b')->create();
        ChannelFactory::new()->inCommunity($other)->with(['identifier' => 'ch-foreign'])->create();

        $section = $this->jsonClient($admin)->request('POST', '/api/v1/communities/c-move-a/sections', ['json' => [
            'name' => 'Foreign',
        ]])->toArray();

        $this->jsonClient($admin)->request('PATCH', '/api/v1/communities/c-move-b/channels/ch-foreign', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['section' => $section['@id']],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAdminCanDeleteChannel(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('c-del')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ch-del'])->create();

        $this->jsonClient($admin)->request('DELETE', '/api/v1/communities/c-del/channels/ch-del');

        self::assertResponseStatusCodeSame(204);
    }

    public function testRegularUserCannotDeleteChannel(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c-del-deny')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ch-del-deny'])->create();

        $this->jsonClient($user)->request('DELETE', '/api/v1/communities/c-del-deny/channels/ch-del-deny');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanUpdateMemberRole(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c-role')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'ch-role'])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);

        $this->jsonClient($admin)->request('PATCH', '/api/v1/communities/c-role/channels/ch-role/members/'.$user->getId().'/role', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['role' => 'moderator'],
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testRegularUserCannotUpdateMemberRole(): void
    {
        $user = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c-role-deny')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'ch-role-deny'])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);
        ChannelMemberFactory::createForUserAndChannel($target, $channel);

        $this->jsonClient($user)->request('PATCH', '/api/v1/communities/c-role-deny/channels/ch-role-deny/members/'.$target->getId().'/role', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['role' => 'moderator'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }
}
