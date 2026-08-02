<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\ChannelSection;
use App\Entity\Community;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\GroupChannelPermissionFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\UserGroupFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class CommunityAdminTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function createSection(Community $community, string $name = 'Text Channels'): ChannelSection
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $section = new ChannelSection();
        $section->setName($name);
        $section->setCommunity($community);
        $em->persist($section);
        $em->flush();

        return $section;
    }

    private function groupsUrl(string $community, ?string $identifier = null): string
    {
        $url = '/api/v1/communities/'.$community.'/groups';

        return null !== $identifier ? $url.'/'.$identifier : $url;
    }

    private function permissionsUrl(string $community, string $identifier, ?string $channelIdentifier = null): string
    {
        $url = '/api/v1/communities/'.$community.'/groups/'.$identifier.'/channel-permissions';

        return null !== $channelIdentifier ? $url.'/'.$channelIdentifier : $url;
    }

    public function testCommunityAdminCanUpdateTheirCommunity(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ca-update')->create();
        CommunityMemberFactory::createAdminForCommunity($user, $community);

        $this->jsonClient($user)->request('PATCH', '/api/v1/communities/ca-update', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Updated Name'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => 'Updated Name']);
    }

    public function testCommunityAdminCannotUpdateOtherCommunity(): void
    {
        $user = UserFactory::createOne();
        $communityA = CommunityFactory::new()->withIdentifier('ca-own')->create();
        $communityB = CommunityFactory::new()->withIdentifier('ca-other')->create();
        CommunityMemberFactory::createAdminForCommunity($user, $communityA);
        CommunityMemberFactory::createForUserAndCommunity($user, $communityB);

        $this->jsonClient($user)->request('PATCH', '/api/v1/communities/ca-other', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Hacked'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRegularMemberCannotUpdateCommunity(): void
    {
        $user = UserFactory::createOne();
        CommunityFactory::new()->withIdentifier('ca-deny-update')->create();
        // no membership at all

        $this->jsonClient($user)->request('PATCH', '/api/v1/communities/ca-deny-update', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Hacked'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testCommunityAdminCanCreateChannel(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ca-ch-create')->create();
        CommunityMemberFactory::createAdminForCommunity($user, $community);

        $this->jsonClient($user)->request('POST', '/api/v1/channels', ['json' => [
            'name' => 'New Channel',
            'community' => '/api/v1/communities/ca-ch-create',
        ]]);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains(['name' => 'New Channel']);
    }

    public function testCommunityAdminCanUpdateChannel(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ca-ch-update')->create();
        CommunityMemberFactory::createAdminForCommunity($user, $community);
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ca-ch-u', 'name' => 'Old'])->create();

        $this->jsonClient($user)->request('PATCH', '/api/v1/communities/ca-ch-update/channels/ca-ch-u', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Updated'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => 'Updated']);
    }

    public function testCommunityAdminCanDeleteChannel(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ca-ch-delete')->create();
        CommunityMemberFactory::createAdminForCommunity($user, $community);
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ca-ch-d'])->create();

        $this->jsonClient($user)->request('DELETE', '/api/v1/communities/ca-ch-delete/channels/ca-ch-d');

        self::assertResponseStatusCodeSame(204);
    }

    public function testCommunityAdminCannotManageChannelInOtherCommunity(): void
    {
        $user = UserFactory::createOne();
        $communityA = CommunityFactory::new()->withIdentifier('ca-ch-own')->create();
        $communityB = CommunityFactory::new()->withIdentifier('ca-ch-foreign')->create();
        CommunityMemberFactory::createAdminForCommunity($user, $communityA);
        ChannelFactory::new()->inCommunity($communityB)->with(['identifier' => 'ca-ch-f'])->create();

        $this->jsonClient($user)->request('DELETE', '/api/v1/communities/ca-ch-foreign/channels/ca-ch-f');

        self::assertResponseStatusCodeSame(403);
    }

    public function testCommunityAdminCanAddChannelMember(): void
    {
        $admin = UserFactory::createOne();
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ca-cm-add')->create();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'ca-private'])->create();

        $this->jsonClient($admin)->request('POST', '/api/v1/communities/ca-cm-add/channels/ca-private/members', ['json' => [
            'userId' => $member->getId(),
        ]]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testCommunityAdminCanRemoveChannelMember(): void
    {
        $admin = UserFactory::createOne();
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ca-cm-remove')->create();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'ca-priv-r'])->create();
        ChannelMemberFactory::createForUserAndChannel($member, $channel);

        $this->jsonClient($admin)->request('DELETE', '/api/v1/communities/ca-cm-remove/channels/ca-priv-r/members/'.$member->getId());

        self::assertResponseStatusCodeSame(204);
    }

    public function testCommunityAdminCanUpdateSection(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ca-sec-update')->create();
        CommunityMemberFactory::createAdminForCommunity($user, $community);
        $section = $this->createSection($community, 'Old Name');

        $this->jsonClient($user)->request('PATCH', '/api/v1/communities/ca-sec-update/sections/'.$section->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'New Name'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => 'New Name']);
    }

    public function testCommunityAdminCanDeleteSection(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ca-sec-del')->create();
        CommunityMemberFactory::createAdminForCommunity($user, $community);
        $section = $this->createSection($community, 'To Delete');

        $this->jsonClient($user)->request('DELETE', '/api/v1/communities/ca-sec-del/sections/'.$section->getId());

        self::assertResponseStatusCodeSame(204);
    }

    public function testCommunityAdminCannotDeleteSectionOfOtherCommunity(): void
    {
        $user = UserFactory::createOne();
        $communityA = CommunityFactory::new()->withIdentifier('ca-sec-own')->create();
        $communityB = CommunityFactory::new()->withIdentifier('ca-sec-foreign')->create();
        CommunityMemberFactory::createAdminForCommunity($user, $communityA);
        $section = $this->createSection($communityB, 'Foreign Section');

        $this->jsonClient($user)->request('DELETE', '/api/v1/communities/ca-sec-foreign/sections/'.$section->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testCommunityAdminCanCreateGroup(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ca-grp-create')->create();
        CommunityMemberFactory::createAdminForCommunity($user, $community);

        $this->jsonClient($user)->request('POST', $this->groupsUrl('ca-grp-create'), ['json' => [
            'name' => 'Moderators',
        ]]);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains(['name' => 'Moderators']);
    }

    public function testCommunityAdminCanUpdateGroup(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ca-grp-update')->create();
        CommunityMemberFactory::createAdminForCommunity($user, $community);
        $group = UserGroupFactory::createInCommunity($community);

        $this->jsonClient($user)->request('PATCH', $this->groupsUrl('ca-grp-update', $group->getIdentifier()), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Renamed'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => 'Renamed']);
    }

    public function testCommunityAdminCanDeleteGroup(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ca-grp-delete')->create();
        CommunityMemberFactory::createAdminForCommunity($user, $community);
        $group = UserGroupFactory::createInCommunity($community);

        $this->jsonClient($user)->request('DELETE', $this->groupsUrl('ca-grp-delete', $group->getIdentifier()));

        self::assertResponseStatusCodeSame(204);
    }

    public function testCommunityAdminCannotManageGroupInOtherCommunity(): void
    {
        $user = UserFactory::createOne();
        $communityA = CommunityFactory::new()->withIdentifier('ca-grp-own')->create();
        $communityB = CommunityFactory::new()->withIdentifier('ca-grp-foreign')->create();
        CommunityMemberFactory::createAdminForCommunity($user, $communityA);
        $group = UserGroupFactory::createInCommunity($communityB);

        $this->jsonClient($user)->request('DELETE', $this->groupsUrl('ca-grp-foreign', $group->getIdentifier()));

        // 404, not 403 — non-members of the community cannot see the group
        // exists at all (VIEW denied maps to not-found).
        self::assertResponseStatusCodeSame(404);
    }

    public function testCommunityAdminSeesHiddenGroups(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ca-grp-hidden')->create();
        CommunityMemberFactory::createAdminForCommunity($user, $community);
        UserGroupFactory::createInCommunity($community, ['isHidden' => true]);

        $response = $this->jsonClient($user)->request('GET', $this->groupsUrl('ca-grp-hidden'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $response->toArray()['hydra:member']);
    }

    public function testRegularMemberCannotSeeHiddenGroups(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ca-grp-hidden-deny')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        UserGroupFactory::createInCommunity($community, ['isHidden' => true]);

        $response = $this->jsonClient($user)->request('GET', $this->groupsUrl('ca-grp-hidden-deny'));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $response->toArray()['hydra:member']);
    }

    public function testCommunityAdminCanListChannelPermissions(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ca-perm-list')->create();
        CommunityMemberFactory::createAdminForCommunity($user, $community);
        $group = UserGroupFactory::createInCommunity($community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ca-perm-ch'])->create();
        GroupChannelPermissionFactory::createForGroupAndChannel($group, $channel);

        $response = $this->jsonClient($user)->request('GET', $this->permissionsUrl('ca-perm-list', $group->getIdentifier()));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $response->toArray()['hydra:member']);
    }

    public function testCommunityAdminCanSetChannelPermission(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ca-perm-set')->create();
        CommunityMemberFactory::createAdminForCommunity($user, $community);
        $group = UserGroupFactory::createInCommunity($community);
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ca-perm-s-ch'])->create();

        $this->jsonClient($user)->request('PUT', $this->permissionsUrl('ca-perm-set', $group->getIdentifier(), 'ca-perm-s-ch'), ['json' => [
            'role' => 'member',
        ]]);

        self::assertResponseIsSuccessful();
    }

    public function testCommunityAdminCanRemoveChannelPermission(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ca-perm-del')->create();
        CommunityMemberFactory::createAdminForCommunity($user, $community);
        $group = UserGroupFactory::createInCommunity($community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ca-perm-d-ch'])->create();
        GroupChannelPermissionFactory::createForGroupAndChannel($group, $channel);

        $this->jsonClient($user)->request('DELETE', $this->permissionsUrl('ca-perm-del', $group->getIdentifier(), 'ca-perm-d-ch'));

        self::assertResponseStatusCodeSame(204);
    }

    public function testRegularMemberCannotListChannelPermissions(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ca-perm-deny')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $group = UserGroupFactory::createInCommunity($community);

        $this->jsonClient($user)->request('GET', $this->permissionsUrl('ca-perm-deny', $group->getIdentifier()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testCommunityAdminSeesPrivateChannelsInCommunity(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ca-priv-vis')->create();
        CommunityMemberFactory::createAdminForCommunity($user, $community);
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ca-pub'])->create();
        ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'ca-priv'])->create();

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/ca-priv-vis');

        self::assertResponseIsSuccessful();
        $identifiers = array_column($response->toArray()['channels'], 'identifier');
        self::assertContains('ca-pub', $identifiers);
        self::assertContains('ca-priv', $identifiers);
    }

    public function testRegularMemberCannotSeePrivateChannelsTheyAreNotMemberOf(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ca-priv-deny')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ca-pub2'])->create();
        ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'ca-priv2'])->create();

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/ca-priv-deny');

        self::assertResponseIsSuccessful();
        $identifiers = array_column($response->toArray()['channels'], 'identifier');
        self::assertContains('ca-pub2', $identifiers);
        self::assertNotContains('ca-priv2', $identifiers);
    }
}
