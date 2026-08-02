<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Negative coverage for the channel-member management ops (add / remove / role
 * change). These are privilege-escalation surfaces — assert at the HTTP layer
 * that the voter is actually wired so a regression can't silently open them.
 */
class ChannelMemberPermissionTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testNonAdminCannotAddChannelMember(): void
    {
        $member = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cmp-add')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'cmp-add-ch'])->create();

        $this->jsonClient($member)->request('POST', '/api/v1/communities/cmp-add/channels/cmp-add-ch/members', [
            'json' => ['userId' => $target->getId()],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonAdminCannotRemoveChannelMember(): void
    {
        $member = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cmp-rm')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'cmp-rm-ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($target, $channel);

        $this->jsonClient($member)->request('DELETE', '/api/v1/communities/cmp-rm/channels/cmp-rm-ch/members/'.$target->getId());

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonAdminCannotChangeMemberRole(): void
    {
        $member = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cmp-role')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'cmp-role-ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($target, $channel);

        $this->jsonClient($member)->request(
            'PATCH',
            '/api/v1/communities/cmp-role/channels/cmp-role-ch/members/'.$target->getId().'/role',
            [
                'json' => ['role' => 'moderator'],
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
            ],
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testCommunityAdminCanChangeMemberRole(): void
    {
        $admin = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cmp-role-ok')->create();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'cmp-role-ok-ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($target, $channel);

        $this->jsonClient($admin)->request(
            'PATCH',
            '/api/v1/communities/cmp-role-ok/channels/cmp-role-ok-ch/members/'.$target->getId().'/role',
            [
                'json' => ['role' => 'moderator'],
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
            ],
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testChangeRoleWithNullRoleReturns422(): void
    {
        $admin = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cmp-role-422')->create();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'cmp-role-422-ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($target, $channel);

        $this->jsonClient($admin)->request(
            'PATCH',
            '/api/v1/communities/cmp-role-422/channels/cmp-role-422-ch/members/'.$target->getId().'/role',
            [
                'json' => [],
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
            ],
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testAnonymousCannotAddChannelMember(): void
    {
        $community = CommunityFactory::new()->withIdentifier('cmp-anon')->create();
        $target = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'cmp-anon-ch'])->create();

        $this->jsonClient()->request('POST', '/api/v1/communities/cmp-anon/channels/cmp-anon-ch/members', [
            'json' => ['userId' => $target->getId()],
        ]);

        self::assertResponseStatusCodeSame(401);
    }
}
