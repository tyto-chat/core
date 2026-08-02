<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use ApiPlatform\Metadata\IriConverterInterface;
use App\Entity\UserGroup;
use App\Enum\Channel\ChannelRole;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\GroupChannelPermissionFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\UserGroupFactory;
use App\Tests\Factory\UserGroupMemberFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class UserGroupTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function groupsUrl(string $community, ?string $identifier = null): string
    {
        $url = '/api/v1/communities/'.$community.'/groups';
        if (null !== $identifier) {
            $url .= '/'.$identifier;
        }

        return $url;
    }

    private function membersUrl(string $community, string $identifier, ?int $userId = null): string
    {
        $url = '/api/v1/communities/'.$community.'/groups/'.$identifier.'/members';
        if (null !== $userId) {
            $url .= '/'.$userId;
        }

        return $url;
    }

    private function permissionsUrl(string $community, string $identifier, ?string $channelIdentifier = null): string
    {
        $url = '/api/v1/communities/'.$community.'/groups/'.$identifier.'/channel-permissions';
        if (null !== $channelIdentifier) {
            $url .= '/'.$channelIdentifier;
        }

        return $url;
    }

    private function transferUrl(UserGroup $group): string
    {
        $iri = static::getContainer()->get(IriConverterInterface::class)->getIriFromResource($group);

        return $iri.'/transfer-ownership';
    }

    public function testAdminCanCreateGroup(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('ug-create')->create();

        $this->jsonClient($admin)->request('POST', $this->groupsUrl('ug-create'), ['json' => [
            'name' => 'Moderators',
            'icon' => '🛡',
            'color' => '#ff0000',
            'isHidden' => false,
        ]]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) self::getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('Moderators', $data['name']);
        self::assertSame('🛡', $data['icon']);
        self::assertSame('#ff0000', $data['color']);
        self::assertFalse($data['isHidden']);
        self::assertArrayHasKey('identifier', $data);
        self::assertSame(0, $data['memberCount']);
    }

    public function testNonAdminCannotCreateGroup(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-create-deny')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        $this->jsonClient($user)->request('POST', $this->groupsUrl('ug-create-deny'), ['json' => [
            'name' => 'Sneaky',
        ]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousCannotCreateGroup(): void
    {
        CommunityFactory::new()->withIdentifier('ug-create-anon')->create();

        $this->jsonClient()->request('POST', $this->groupsUrl('ug-create-anon'), ['json' => ['name' => 'X']]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testGroupIconAndColorPersistedCorrectly(): void
    {
        $admin = UserFactory::new()->admin()->create();
        CommunityFactory::new()->withIdentifier('ug-icon-color')->create();

        $this->jsonClient($admin)->request('POST', $this->groupsUrl('ug-icon-color'), ['json' => [
            'name' => 'Gamers',
            'icon' => '🎮',
            'color' => '#1a2b3c',
        ]]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) self::getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('🎮', $data['icon']);
        self::assertSame('#1a2b3c', $data['color']);
    }

    public function testGroupWithNoChannelPermissionsIsValid(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('ug-no-perms')->create();
        $group = UserGroupFactory::createInCommunity($community, ['name' => 'Pure Label']);

        $response = $this->jsonClient($admin)->request('GET', $this->groupsUrl('ug-no-perms', $group->getIdentifier()));

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertSame('Pure Label', $data['name']);
        self::assertSame(0, $data['memberCount']);
    }

    public function testMemberCanListPublicGroups(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-list')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        UserGroupFactory::createInCommunity($community, ['name' => 'Public A']);
        UserGroupFactory::createInCommunity($community, ['name' => 'Public B']);

        $response = $this->jsonClient($user)->request('GET', $this->groupsUrl('ug-list'));

        self::assertResponseIsSuccessful();
        self::assertCount(2, $response->toArray()['hydra:member']);
    }

    public function testHiddenGroupAbsentFromListForNonMember(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-hidden-list')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        UserGroupFactory::createInCommunity($community, ['name' => 'Visible']);
        UserGroupFactory::new()->inCommunity($community)->hidden()->with(['name' => 'Secret'])->create();

        $response = $this->jsonClient($user)->request('GET', $this->groupsUrl('ug-hidden-list'));

        self::assertResponseIsSuccessful();
        $members = $response->toArray()['hydra:member'];
        self::assertCount(1, $members);
        self::assertSame('Visible', $members[0]['name']);
    }

    public function testHiddenGroupVisibleInListForMember(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-hidden-member')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $hidden = UserGroupFactory::new()->inCommunity($community)->hidden()->with(['name' => 'Secret'])->create();
        UserGroupMemberFactory::createForUserAndGroup($user, $hidden);

        $response = $this->jsonClient($user)->request('GET', $this->groupsUrl('ug-hidden-member'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $response->toArray()['hydra:member']);
    }

    public function testAdminSeesAllGroupsIncludingHidden(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('ug-admin-list')->create();
        UserGroupFactory::createInCommunity($community, ['name' => 'Visible']);
        UserGroupFactory::new()->inCommunity($community)->hidden()->with(['name' => 'Secret'])->create();

        $response = $this->jsonClient($admin)->request('GET', $this->groupsUrl('ug-admin-list'));

        self::assertResponseIsSuccessful();
        self::assertCount(2, $response->toArray()['hydra:member']);
    }

    public function testHiddenGroupReturns404ForNonMember(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-404')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $hidden = UserGroupFactory::new()->inCommunity($community)->hidden()->create();

        $this->jsonClient($user)->request('GET', $this->groupsUrl('ug-404', $hidden->getIdentifier()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testHiddenGroupReturns200ForMember(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-hidden-ok')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $hidden = UserGroupFactory::new()->inCommunity($community)->hidden()->create();
        UserGroupMemberFactory::createForUserAndGroup($user, $hidden);

        $this->jsonClient($user)->request('GET', $this->groupsUrl('ug-hidden-ok', $hidden->getIdentifier()));

        self::assertResponseIsSuccessful();
    }

    public function testHiddenGroupReturns200ForAdmin(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('ug-hidden-admin')->create();
        $hidden = UserGroupFactory::new()->inCommunity($community)->hidden()->create();

        $this->jsonClient($admin)->request('GET', $this->groupsUrl('ug-hidden-admin', $hidden->getIdentifier()));

        self::assertResponseIsSuccessful();
    }

    public function testAdminCanUpdateGroup(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('ug-update')->create();
        $group = UserGroupFactory::createInCommunity($community, ['name' => 'Old Name']);

        $this->jsonClient($admin)->request('PATCH', $this->groupsUrl('ug-update', $group->getIdentifier()), [
            'json' => ['name' => 'New Name', 'icon' => null, 'color' => null, 'isHidden' => true],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) self::getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('New Name', $data['name']);
        self::assertTrue($data['isHidden']);
    }

    public function testPartialUpdateKeepsOmittedFields(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-partial')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        $group = UserGroupFactory::createInCommunity($community, [
            'name' => 'Keep Me',
            'icon' => '🦉',
            'color' => '#112233',
            'isHidden' => true,
            'owner' => $member,
        ]);
        UserGroupMemberFactory::createForUserAndGroup($member, $group);

        $response = $this->jsonClient($admin)->request('PATCH', $this->groupsUrl('ug-partial', $group->getIdentifier()), [
            'json' => ['name' => 'Renamed Only'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertSame('Renamed Only', $data['name']);
        self::assertSame('🦉', $data['icon']);
        self::assertSame('#112233', $data['color']);
        self::assertTrue($data['isHidden']);
        self::assertSame($member->getId(), $data['ownerId']);
    }

    public function testAdminCanAssignOwnerWhoIsAMember(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-owner-set')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        $group = UserGroupFactory::createInCommunity($community);
        UserGroupMemberFactory::createForUserAndGroup($member, $group);

        $this->jsonClient($admin)->request('PATCH', $this->groupsUrl('ug-owner-set', $group->getIdentifier()), [
            'json' => ['name' => $group->getName(), 'icon' => null, 'color' => null, 'isHidden' => false, 'ownerId' => $member->getId()],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) self::getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame($member->getId(), $data['ownerId']);
    }

    public function testAssigningNonMemberAsOwnerReturns422(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $nonMember = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-owner-bad')->create();
        $group = UserGroupFactory::createInCommunity($community);

        $this->jsonClient($admin)->request('PATCH', $this->groupsUrl('ug-owner-bad', $group->getIdentifier()), [
            'json' => ['name' => $group->getName(), 'icon' => null, 'color' => null, 'isHidden' => false, 'ownerId' => $nonMember->getId()],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testNonAdminCannotUpdateGroup(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-update-deny')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $group = UserGroupFactory::createInCommunity($community);

        $this->jsonClient($user)->request('PATCH', $this->groupsUrl('ug-update-deny', $group->getIdentifier()), [
            'json' => ['name' => 'Hacked', 'icon' => null, 'color' => null, 'isHidden' => false],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanDeleteGroup(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('ug-delete')->create();
        $group = UserGroupFactory::createInCommunity($community);

        $this->jsonClient($admin)->request('DELETE', $this->groupsUrl('ug-delete', $group->getIdentifier()));

        self::assertResponseStatusCodeSame(204);
    }

    public function testNonAdminCannotDeleteGroup(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-delete-deny')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $group = UserGroupFactory::createInCommunity($community);

        $this->jsonClient($user)->request('DELETE', $this->groupsUrl('ug-delete-deny', $group->getIdentifier()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanAddMember(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-add-member')->create();
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $group = UserGroupFactory::createInCommunity($community);

        $this->jsonClient($admin)->request('POST', $this->membersUrl('ug-add-member', $group->getIdentifier()), ['json' => [
            'userId' => $target->getId(),
        ]]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) self::getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame($target->getId(), $data['userId']);
    }

    public function testOwnerCanAddMember(): void
    {
        $owner = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-owner-add')->create();
        CommunityMemberFactory::createForUserAndCommunity($owner, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $group = UserGroupFactory::new()->inCommunity($community)->withOwner($owner)->create();
        UserGroupMemberFactory::createForUserAndGroup($owner, $group);

        $this->jsonClient($owner)->request('POST', $this->membersUrl('ug-owner-add', $group->getIdentifier()), ['json' => [
            'userId' => $target->getId(),
        ]]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testNonOwnerNonAdminCannotAddMember(): void
    {
        $user = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-add-deny')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $group = UserGroupFactory::createInCommunity($community);

        $this->jsonClient($user)->request('POST', $this->membersUrl('ug-add-deny', $group->getIdentifier()), ['json' => [
            'userId' => $target->getId(),
        ]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAddingNonCommunityMemberReturns403(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-add-nonmember')->create();
        $group = UserGroupFactory::createInCommunity($community);

        $this->jsonClient($admin)->request('POST', $this->membersUrl('ug-add-nonmember', $group->getIdentifier()), ['json' => [
            'userId' => $outsider->getId(),
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testGroupAddedNotificationSentOnMemberAdd(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-notif-add')->create();
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $group = UserGroupFactory::createInCommunity($community, ['name' => 'VIP']);

        $this->jsonClient($admin)->request('POST', $this->membersUrl('ug-notif-add', $group->getIdentifier()), ['json' => [
            'userId' => $target->getId(),
        ]]);

        self::assertResponseStatusCodeSame(201);

        $notifResponse = $this->jsonClient($target)->request('GET', '/api/v1/communities/ug-notif-add/notifications');
        self::assertResponseIsSuccessful();
        $notifications = $notifResponse->toArray()['hydra:member'];
        self::assertCount(1, $notifications);
        self::assertSame('group_added', $notifications[0]['type']);
        self::assertSame('VIP', $notifications[0]['groupName']);
    }

    public function testAdminCanRemoveMember(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-remove-member')->create();
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $group = UserGroupFactory::createInCommunity($community);
        UserGroupMemberFactory::createForUserAndGroup($target, $group);

        $this->jsonClient($admin)->request('DELETE', $this->membersUrl('ug-remove-member', $group->getIdentifier(), $target->getId()));

        self::assertResponseStatusCodeSame(204);
    }

    public function testGroupRemovedNotificationSentWhenAdminRemovesMember(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-notif-remove')->create();
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $group = UserGroupFactory::createInCommunity($community, ['name' => 'VIP']);
        UserGroupMemberFactory::createForUserAndGroup($target, $group);

        $this->jsonClient($admin)->request('DELETE', $this->membersUrl('ug-notif-remove', $group->getIdentifier(), $target->getId()));

        self::assertResponseStatusCodeSame(204);

        $notifResponse = $this->jsonClient($target)->request('GET', '/api/v1/communities/ug-notif-remove/notifications');
        $notifications = $notifResponse->toArray()['hydra:member'];
        self::assertCount(1, $notifications);
        self::assertSame('group_removed', $notifications[0]['type']);
        self::assertSame('VIP', $notifications[0]['groupName']);
    }

    public function testNoNotificationOnSelfLeave(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-self-leave')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $group = UserGroupFactory::createInCommunity($community);
        UserGroupMemberFactory::createForUserAndGroup($user, $group);

        $this->jsonClient($user)->request('DELETE', $this->membersUrl('ug-self-leave', $group->getIdentifier(), $user->getId()));

        self::assertResponseStatusCodeSame(204);

        $notifResponse = $this->jsonClient($user)->request('GET', '/api/v1/communities/ug-self-leave/notifications');
        self::assertCount(0, $notifResponse->toArray()['hydra:member']);
    }

    public function testOwnerCanRemoveMember(): void
    {
        $owner = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-owner-remove')->create();
        CommunityMemberFactory::createForUserAndCommunity($owner, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $group = UserGroupFactory::new()->inCommunity($community)->withOwner($owner)->create();
        UserGroupMemberFactory::createForUserAndGroup($owner, $group);
        UserGroupMemberFactory::createForUserAndGroup($target, $group);

        $this->jsonClient($owner)->request('DELETE', $this->membersUrl('ug-owner-remove', $group->getIdentifier(), $target->getId()));

        self::assertResponseStatusCodeSame(204);
    }

    public function testOwnerCannotLeaveOwnGroup(): void
    {
        $owner = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-owner-leave')->create();
        CommunityMemberFactory::createForUserAndCommunity($owner, $community);
        $group = UserGroupFactory::new()->inCommunity($community)->withOwner($owner)->create();
        UserGroupMemberFactory::createForUserAndGroup($owner, $group);

        $this->jsonClient($owner)->request('DELETE', $this->membersUrl('ug-owner-leave', $group->getIdentifier(), $owner->getId()));

        self::assertResponseStatusCodeSame(422);
    }

    public function testOwnerCanLeaveAfterTransfer(): void
    {
        $owner = UserFactory::createOne();
        $successor = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-owner-transfer-leave')->create();
        CommunityMemberFactory::createForUserAndCommunity($owner, $community);
        CommunityMemberFactory::createForUserAndCommunity($successor, $community);
        $group = UserGroupFactory::new()->inCommunity($community)->withOwner($owner)->create();
        UserGroupMemberFactory::createForUserAndGroup($owner, $group);
        UserGroupMemberFactory::createForUserAndGroup($successor, $group);

        $this->jsonClient($owner)->request('POST', $this->transferUrl($group), ['json' => [
            'userId' => $successor->getId(),
        ]]);
        self::assertResponseStatusCodeSame(201);

        $this->jsonClient($owner)->request('DELETE', $this->membersUrl('ug-owner-transfer-leave', $group->getIdentifier(), $owner->getId()));
        self::assertResponseStatusCodeSame(204);
    }

    public function testAdminRemovingOwnerClearsOwnerOnGroup(): void
    {
        $owner = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-admin-remove-owner')->create();
        CommunityMemberFactory::createForUserAndCommunity($owner, $community);
        $group = UserGroupFactory::new()->inCommunity($community)->withOwner($owner)->create();
        UserGroupMemberFactory::createForUserAndGroup($owner, $group);

        $admin = UserFactory::new()->admin()->create();

        $this->jsonClient($admin)->request('DELETE', $this->membersUrl('ug-admin-remove-owner', $group->getIdentifier(), $owner->getId()));
        self::assertResponseStatusCodeSame(204);

        $response = $this->jsonClient($admin)->request('GET', $this->groupsUrl('ug-admin-remove-owner', $group->getIdentifier()));
        self::assertNull($response->toArray()['ownerId']);
    }

    public function testMyGroupsListsOwnedAndMemberGroups(): void
    {
        $user = UserFactory::createOne();
        $other = UserFactory::createOne();
        $communityA = CommunityFactory::new()->withIdentifier('mg-a')->create();
        $communityB = CommunityFactory::new()->withIdentifier('mg-b')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $communityA);
        CommunityMemberFactory::createForUserAndCommunity($user, $communityB);
        CommunityMemberFactory::createForUserAndCommunity($other, $communityB);

        $owned = UserGroupFactory::new()->inCommunity($communityA)->withOwner($user)->with(['name' => 'Owned Crew'])->create();
        UserGroupMemberFactory::createForUserAndGroup($user, $owned);

        $memberOf = UserGroupFactory::new()->inCommunity($communityB)->withOwner($other)->with(['name' => 'Other Crew'])->create();
        UserGroupMemberFactory::createForUserAndGroup($other, $memberOf);
        UserGroupMemberFactory::createForUserAndGroup($user, $memberOf);

        UserGroupFactory::createInCommunity($communityB, ['name' => 'Unrelated']);

        $response = $this->jsonClient($user)->request('GET', '/api/v1/me/groups');

        self::assertResponseStatusCodeSame(200);
        $items = $response->toArray()['items'];
        self::assertCount(2, $items);
        $byName = array_column($items, null, 'name');
        self::assertTrue($byName['Owned Crew']['isOwner']);
        self::assertSame('mg-a', $byName['Owned Crew']['communityIdentifier']);
        self::assertSame(1, $byName['Owned Crew']['memberCount']);
        self::assertFalse($byName['Other Crew']['isOwner']);
        self::assertSame(2, $byName['Other Crew']['memberCount']);
        self::assertArrayHasKey('communityName', $byName['Other Crew']);
    }

    public function testMyGroupsRequiresAuth(): void
    {
        $this->jsonClient()->request('GET', '/api/v1/me/groups');

        self::assertResponseStatusCodeSame(401);
    }

    public function testNonAdminNonOwnerCannotRemoveOther(): void
    {
        $user = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-remove-deny')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $group = UserGroupFactory::createInCommunity($community);
        UserGroupMemberFactory::createForUserAndGroup($user, $group);
        UserGroupMemberFactory::createForUserAndGroup($target, $group);

        $this->jsonClient($user)->request('DELETE', $this->membersUrl('ug-remove-deny', $group->getIdentifier(), $target->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testMemberCanListGroupMembers(): void
    {
        $user = UserFactory::createOne();
        $other = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-list-members')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $group = UserGroupFactory::createInCommunity($community);
        UserGroupMemberFactory::createForUserAndGroup($user, $group);
        UserGroupMemberFactory::createForUserAndGroup($other, $group);

        $response = $this->jsonClient($user)->request('GET', $this->membersUrl('ug-list-members', $group->getIdentifier()));

        self::assertResponseIsSuccessful();
        self::assertCount(2, $response->toArray()['hydra:member']);
    }

    public function testNonMemberCannotListGroupMembers(): void
    {
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-list-members-deny')->create();
        CommunityMemberFactory::createForUserAndCommunity($outsider, $community);
        $group = UserGroupFactory::createInCommunity($community);

        $this->jsonClient($outsider)->request('GET', $this->membersUrl('ug-list-members-deny', $group->getIdentifier()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testNonMemberOfHiddenGroupGets404OnMembersList(): void
    {
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-hidden-members')->create();
        CommunityMemberFactory::createForUserAndCommunity($outsider, $community);
        $hidden = UserGroupFactory::new()->inCommunity($community)->hidden()->create();

        $this->jsonClient($outsider)->request('GET', $this->membersUrl('ug-hidden-members', $hidden->getIdentifier()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testCommunityMemberResponseIncludesGroups(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-member-groups')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $group = UserGroupFactory::createInCommunity($community, ['name' => 'Testers']);
        UserGroupMemberFactory::createForUserAndGroup($user, $group);

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/ug-member-groups/members');

        self::assertResponseIsSuccessful();
        $members = $response->toArray()['hydra:member'];
        $mine = array_values(array_filter($members, fn ($m) => $m['userId'] === $user->getId()))[0];
        self::assertCount(1, $mine['groups']);
        self::assertSame('Testers', $mine['groups'][0]['name']);
    }

    public function testHiddenGroupAbsentFromCommunityMemberResponseForNonMember(): void
    {
        $viewer = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-hidden-badge')->create();
        CommunityMemberFactory::createForUserAndCommunity($viewer, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $hidden = UserGroupFactory::new()->inCommunity($community)->hidden()->with(['name' => 'Secret'])->create();
        UserGroupMemberFactory::createForUserAndGroup($target, $hidden);

        $response = $this->jsonClient($viewer)->request('GET', '/api/v1/communities/ug-hidden-badge/members');

        self::assertResponseIsSuccessful();
        $members = $response->toArray()['hydra:member'];
        $targetMember = array_values(array_filter($members, fn ($m) => $m['userId'] === $target->getId()))[0];
        self::assertCount(0, $targetMember['groups']);
    }

    public function testAdminSeesHiddenGroupInMemberResponse(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-admin-badge')->create();
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $hidden = UserGroupFactory::new()->inCommunity($community)->hidden()->with(['name' => 'Secret'])->create();
        UserGroupMemberFactory::createForUserAndGroup($target, $hidden);

        $response = $this->jsonClient($admin)->request('GET', '/api/v1/communities/ug-admin-badge/members');

        self::assertResponseIsSuccessful();
        $members = $response->toArray()['hydra:member'];
        $targetMember = array_values(array_filter($members, fn ($m) => $m['userId'] === $target->getId()))[0];
        self::assertCount(1, $targetMember['groups']);
        self::assertSame('Secret', $targetMember['groups'][0]['name']);
    }

    public function testUserBelongingToMultipleGroupsShowsAllInMemberResponse(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-multi-groups')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $g1 = UserGroupFactory::createInCommunity($community, ['name' => 'Alpha']);
        $g2 = UserGroupFactory::createInCommunity($community, ['name' => 'Beta']);
        UserGroupMemberFactory::createForUserAndGroup($user, $g1);
        UserGroupMemberFactory::createForUserAndGroup($user, $g2);

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/ug-multi-groups/members');

        self::assertResponseIsSuccessful();
        $members = $response->toArray()['hydra:member'];
        $mine = array_values(array_filter($members, fn ($m) => $m['userId'] === $user->getId()))[0];
        self::assertCount(2, $mine['groups']);
    }

    public function testMemberCountReflectsCurrentMembership(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $u1 = UserFactory::createOne();
        $u2 = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-count')->create();
        CommunityMemberFactory::createForUserAndCommunity($u1, $community);
        CommunityMemberFactory::createForUserAndCommunity($u2, $community);
        $group = UserGroupFactory::createInCommunity($community);
        UserGroupMemberFactory::createForUserAndGroup($u1, $group);
        UserGroupMemberFactory::createForUserAndGroup($u2, $group);

        $response = $this->jsonClient($admin)->request('GET', $this->groupsUrl('ug-count', $group->getIdentifier()));

        self::assertResponseIsSuccessful();
        self::assertSame(2, $response->toArray()['memberCount']);
    }

    public function testAdminCanSetChannelPermission(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('ug-perm-set')->create();
        $group = UserGroupFactory::createInCommunity($community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ug-ch-perm'])->create();

        $this->jsonClient($admin)->request('PUT', $this->permissionsUrl('ug-perm-set', $group->getIdentifier(), 'ug-ch-perm'), ['json' => [
            'role' => 'moderator',
        ]]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) self::getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('moderator', $data['role']);
    }

    public function testAdminCanDeleteChannelPermission(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('ug-perm-del')->create();
        $group = UserGroupFactory::createInCommunity($community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ug-ch-del'])->create();
        GroupChannelPermissionFactory::createForGroupAndChannel($group, $channel);

        $this->jsonClient($admin)->request('DELETE', $this->permissionsUrl('ug-perm-del', $group->getIdentifier(), 'ug-ch-del'));

        self::assertResponseStatusCodeSame(204);
    }

    public function testAdminCanListChannelPermissions(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('ug-perm-list')->create();
        $group = UserGroupFactory::createInCommunity($community);
        $ch1 = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ug-ch-l1'])->create();
        $ch2 = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ug-ch-l2'])->create();
        GroupChannelPermissionFactory::createForGroupAndChannel($group, $ch1);
        GroupChannelPermissionFactory::createForGroupAndChannel($group, $ch2, ChannelRole::Moderator);

        $response = $this->jsonClient($admin)->request('GET', $this->permissionsUrl('ug-perm-list', $group->getIdentifier()));

        self::assertResponseIsSuccessful();
        self::assertCount(2, $response->toArray()['hydra:member']);
    }

    public function testNonAdminCannotManageChannelPermissions(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-perm-deny')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $group = UserGroupFactory::createInCommunity($community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ug-ch-deny'])->create();

        $this->jsonClient($user)->request('PUT', $this->permissionsUrl('ug-perm-deny', $group->getIdentifier(), 'ug-ch-deny'), ['json' => [
            'role' => 'member',
        ]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnerCanListChannelPermissions(): void
    {
        $owner = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-perm-owner')->create();
        CommunityMemberFactory::createForUserAndCommunity($owner, $community);
        $group = UserGroupFactory::new()->inCommunity($community)->withOwner($owner)->create();
        UserGroupMemberFactory::createForUserAndGroup($owner, $group);
        $ch = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ug-ch-owner'])->create();
        GroupChannelPermissionFactory::createForGroupAndChannel($group, $ch);

        $response = $this->jsonClient($owner)->request('GET', $this->permissionsUrl('ug-perm-owner', $group->getIdentifier()));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $response->toArray()['hydra:member']);
    }

    public function testPlainMemberCannotListChannelPermissions(): void
    {
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-perm-member')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        $group = UserGroupFactory::createInCommunity($community);
        UserGroupMemberFactory::createForUserAndGroup($member, $group);

        $this->jsonClient($member)->request('GET', $this->permissionsUrl('ug-perm-member', $group->getIdentifier()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnerCanTransferOwnership(): void
    {
        $owner = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-xfer')->create();
        CommunityMemberFactory::createForUserAndCommunity($owner, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $group = UserGroupFactory::new()->inCommunity($community)->withOwner($owner)->create();
        UserGroupMemberFactory::createForUserAndGroup($owner, $group);
        UserGroupMemberFactory::createForUserAndGroup($target, $group);

        $this->jsonClient($owner)->request('POST', $this->transferUrl($group), ['json' => [
            'userId' => $target->getId(),
        ]]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) self::getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame($target->getId(), $data['ownerId']);
    }

    public function testTransferToNonGroupMemberReturns422(): void
    {
        $owner = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-xfer-bad')->create();
        CommunityMemberFactory::createForUserAndCommunity($owner, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $group = UserGroupFactory::new()->inCommunity($community)->withOwner($owner)->create();
        UserGroupMemberFactory::createForUserAndGroup($owner, $group);

        $this->jsonClient($owner)->request('POST', $this->transferUrl($group), ['json' => [
            'userId' => $target->getId(),
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testNonOwnerNonAdminCannotTransferOwnership(): void
    {
        $owner = UserFactory::createOne();
        $stranger = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-xfer-deny')->create();
        CommunityMemberFactory::createForUserAndCommunity($owner, $community);
        CommunityMemberFactory::createForUserAndCommunity($stranger, $community);
        $group = UserGroupFactory::new()->inCommunity($community)->withOwner($owner)->create();
        UserGroupMemberFactory::createForUserAndGroup($owner, $group);
        UserGroupMemberFactory::createForUserAndGroup($stranger, $group);

        $this->jsonClient($stranger)->request('POST', $this->transferUrl($group), ['json' => [
            'userId' => $stranger->getId(),
        ]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testGroupMemberRoleGrantsAccessToPrivateChannel(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-voter-access')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $private = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ug-priv', 'private' => true])->create();
        $group = UserGroupFactory::createInCommunity($community);
        UserGroupMemberFactory::createForUserAndGroup($user, $group);
        GroupChannelPermissionFactory::createForGroupAndChannel($group, $private, ChannelRole::Member);

        $this->jsonClient($user)->request('GET', '/api/v1/communities/ug-voter-access/channels/ug-priv');

        self::assertResponseIsSuccessful();
    }

    public function testGroupWithNoPermissionsGrantsNoChannelAccess(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-voter-no-access')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $private = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ug-priv-denied', 'private' => true])->create();
        $group = UserGroupFactory::createInCommunity($community);
        UserGroupMemberFactory::createForUserAndGroup($user, $group);
        // no GroupChannelPermission created

        $this->jsonClient($user)->request('GET', '/api/v1/communities/ug-voter-no-access/channels/ug-priv-denied');

        self::assertResponseStatusCodeSame(404);
    }

    public function testHigherGroupRoleWinsWhenInMultipleGroups(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-voter-multi')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $private = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ug-priv-multi', 'private' => true])->create();
        $g1 = UserGroupFactory::createInCommunity($community);
        $g2 = UserGroupFactory::createInCommunity($community);
        UserGroupMemberFactory::createForUserAndGroup($user, $g1);
        UserGroupMemberFactory::createForUserAndGroup($user, $g2);
        GroupChannelPermissionFactory::createForGroupAndChannel($g1, $private, ChannelRole::Member);
        GroupChannelPermissionFactory::createForGroupAndChannel($g2, $private, ChannelRole::Moderator);

        $this->jsonClient($user)->request('GET', '/api/v1/communities/ug-voter-multi/channels/ug-priv-multi');

        self::assertResponseIsSuccessful();
    }

    public function testGroupModeratorRoleGrantsModerationRights(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-voter-mod')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ug-ch-mod', 'private' => true])->create();
        $group = UserGroupFactory::createInCommunity($community);
        UserGroupMemberFactory::createForUserAndGroup($user, $group);
        GroupChannelPermissionFactory::createForGroupAndChannel($group, $channel, ChannelRole::Moderator);

        $this->jsonClient($user)->request('GET', '/api/v1/communities/ug-voter-mod/channels/ug-ch-mod');
        self::assertResponseIsSuccessful();

        $membership = $this->plainJsonClient($user)->request('GET', '/api/v1/communities/ug-voter-mod/membership');

        self::assertResponseIsSuccessful();
        self::assertSame('moderator', $membership->toArray()['channelRoles'][(string) $channel->getId()]);
    }

    public function testChannelRolesReflectsGroupRole(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-role-reflect')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ug-ch-reflect'])->create();
        $group = UserGroupFactory::createInCommunity($community);
        UserGroupMemberFactory::createForUserAndGroup($user, $group);
        GroupChannelPermissionFactory::createForGroupAndChannel($group, $channel, ChannelRole::Member);

        $membership = $this->plainJsonClient($user)->request('GET', '/api/v1/communities/ug-role-reflect/membership');

        self::assertResponseIsSuccessful();
        self::assertSame('member', $membership->toArray()['channelRoles'][(string) $channel->getId()]);
    }

    public function testPrivateChannelAppearsInCommunityResponseWhenUserHasGroupAccess(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-comm-priv')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $private = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ug-hidden-ch', 'private' => true])->create();
        $group = UserGroupFactory::createInCommunity($community);
        UserGroupMemberFactory::createForUserAndGroup($user, $group);
        GroupChannelPermissionFactory::createForGroupAndChannel($group, $private, ChannelRole::Member);

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/ug-comm-priv');

        self::assertResponseIsSuccessful();
        $channelIds = array_column($response->toArray()['channels'], 'identifier');
        self::assertContains('ug-hidden-ch', $channelIds);
    }

    public function testGroupNameTooLongReturns422(): void
    {
        $admin = UserFactory::new()->admin()->create();
        CommunityFactory::new()->withIdentifier('ug-name-long')->create();

        $this->jsonClient($admin)->request('POST', $this->groupsUrl('ug-name-long'), ['json' => [
            'name' => str_repeat('a', 101),
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testColorValidationRejectsNamedColor(): void
    {
        $admin = UserFactory::new()->admin()->create();
        CommunityFactory::new()->withIdentifier('ug-color-named')->create();

        $this->jsonClient($admin)->request('POST', $this->groupsUrl('ug-color-named'), ['json' => [
            'name' => 'Bad Color',
            'color' => 'red',
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testColorValidationRejectsUppercaseHex(): void
    {
        $admin = UserFactory::new()->admin()->create();
        CommunityFactory::new()->withIdentifier('ug-color-upper')->create();

        $this->jsonClient($admin)->request('POST', $this->groupsUrl('ug-color-upper'), ['json' => [
            'name' => 'Uppercase Color',
            'color' => '#FF0000',
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAddingAlreadyGroupMemberReturns422(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-dup-member')->create();
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        $group = UserGroupFactory::createInCommunity($community);
        UserGroupMemberFactory::createForUserAndGroup($target, $group);

        $this->jsonClient($admin)->request('POST', $this->membersUrl('ug-dup-member', $group->getIdentifier()), ['json' => [
            'userId' => $target->getId(),
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testGroupFromAnotherCommunityReturns404(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $communityA = CommunityFactory::new()->withIdentifier('ug-iso-a')->create();
        CommunityFactory::new()->withIdentifier('ug-iso-b')->create();
        $group = UserGroupFactory::createInCommunity($communityA);

        $this->jsonClient($admin)->request('GET', $this->groupsUrl('ug-iso-b', $group->getIdentifier()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testChannelPermissionUpsertUpdatesRole(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('ug-perm-upsert')->create();
        $group = UserGroupFactory::createInCommunity($community);
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ug-ch-upsert'])->create();

        $this->jsonClient($admin)->request('PUT', $this->permissionsUrl('ug-perm-upsert', $group->getIdentifier(), 'ug-ch-upsert'), ['json' => [
            'role' => 'member',
        ]]);
        self::assertResponseIsSuccessful();
        self::assertSame('member', json_decode((string) self::getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['role']);

        $this->jsonClient($admin)->request('PUT', $this->permissionsUrl('ug-perm-upsert', $group->getIdentifier(), 'ug-ch-upsert'), ['json' => [
            'role' => 'moderator',
        ]]);
        self::assertResponseIsSuccessful();
        self::assertSame('moderator', json_decode((string) self::getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['role']);

        $response = $this->jsonClient($admin)->request('GET', $this->permissionsUrl('ug-perm-upsert', $group->getIdentifier()));
        self::assertCount(1, $response->toArray()['hydra:member']);
    }

    public function testGroupDeleteCascadesClearsMembersAndPermissions(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ug-cascade')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ug-ch-cascade'])->create();
        $group = UserGroupFactory::createInCommunity($community);
        UserGroupMemberFactory::createForUserAndGroup($user, $group);
        GroupChannelPermissionFactory::createForGroupAndChannel($group, $channel, ChannelRole::Member);
        $identifier = $group->getIdentifier();

        $this->jsonClient($admin)->request('DELETE', $this->groupsUrl('ug-cascade', $identifier));
        self::assertResponseStatusCodeSame(204);

        $this->jsonClient($admin)->request('GET', $this->groupsUrl('ug-cascade', $identifier));
        self::assertResponseStatusCodeSame(404);

        $response = $this->jsonClient($admin)->request('GET', '/api/v1/communities/ug-cascade/members');
        $members = $response->toArray()['hydra:member'];
        $member = array_values(array_filter($members, fn ($m) => $m['userId'] === $user->getId()))[0];
        self::assertCount(0, $member['groups']);
    }

    public function testSetPermissionWithInvalidRoleReturns422(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('ug-perm-bad')->create();
        $group = UserGroupFactory::createInCommunity($community);
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ug-ch-bad'])->create();

        $this->jsonClient($admin)->request('PUT', $this->permissionsUrl('ug-perm-bad', $group->getIdentifier(), 'ug-ch-bad'), ['json' => [
            'role' => 'owner',
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testSetPermissionForForeignChannelReturns404(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $communityA = CommunityFactory::new()->withIdentifier('ug-perm-a')->create();
        $communityB = CommunityFactory::new()->withIdentifier('ug-perm-b')->create();
        $group = UserGroupFactory::createInCommunity($communityA);
        // Channel lives in community B; setting a permission for it via community
        // A's group must not resolve.
        ChannelFactory::new()->inCommunity($communityB)->with(['identifier' => 'ug-ch-foreign'])->create();

        $this->jsonClient($admin)->request('PUT', $this->permissionsUrl('ug-perm-a', $group->getIdentifier(), 'ug-ch-foreign'), ['json' => [
            'role' => 'moderator',
        ]]);

        self::assertResponseStatusCodeSame(404);
    }
}
