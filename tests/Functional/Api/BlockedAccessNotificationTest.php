<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Community;
use App\Entity\Notification;
use App\Entity\User;
use App\Entity\UserBlock;
use App\Enum\Channel\ChannelRole;
use App\Enum\Notification\NotificationType;
use App\Service\Notification\EmailDigestServiceInterface;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\UserGroupFactory;
use App\Tests\Factory\UserGroupMemberFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class BlockedAccessNotificationTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function community(string $identifier, User ...$members): Community
    {
        $community = CommunityFactory::new()->withIdentifier($identifier)->create();
        foreach ($members as $member) {
            CommunityMemberFactory::createForUserAndCommunity($member, $community);
        }

        return $community;
    }

    private function blockDirectly(User $blocker, User $blocked): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new UserBlock())->setBlocker($em->getReference(User::class, $blocker->getId()))->setBlocked($em->getReference(User::class, $blocked->getId())));
        $em->flush();
    }

    private function countNotifications(User $recipient, NotificationType $type): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return (int) $em->createQuery('SELECT COUNT(n.id) FROM '.Notification::class.' n WHERE n.recipient = :r AND n.type = :t')
            ->setParameter('r', $recipient)
            ->setParameter('t', $type)
            ->getSingleScalarResult();
    }

    public function testBlockedGroupOwnerAddingBlockerChangesMembershipWithoutNotification(): void
    {
        $owner = UserFactory::createOne();
        $blocker = UserFactory::createOne();
        $community = $this->community('ban-grp-add', $owner, $blocker);
        $group = UserGroupFactory::new()->inCommunity($community)->withOwner($owner)->create();
        $this->blockDirectly($blocker, $owner);

        $this->jsonClient($owner)->request('POST', '/api/v1/communities/ban-grp-add/groups/'.$group->getIdentifier().'/members', ['json' => ['userId' => $blocker->getId()]]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(0, $this->countNotifications($blocker, NotificationType::GroupAdded));
    }

    public function testBlockedGroupOwnerRemovingBlockerChangesMembershipWithoutNotification(): void
    {
        $owner = UserFactory::createOne();
        $blocker = UserFactory::createOne();
        $community = $this->community('ban-grp-rm', $owner, $blocker);
        $group = UserGroupFactory::new()->inCommunity($community)->withOwner($owner)->create();
        UserGroupMemberFactory::createForUserAndGroup($blocker, $group);
        $this->blockDirectly($blocker, $owner);

        $this->jsonClient($owner)->request('DELETE', '/api/v1/communities/ban-grp-rm/groups/'.$group->getIdentifier().'/members/'.$blocker->getId());

        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->countNotifications($blocker, NotificationType::GroupRemoved));
    }

    public function testStaffGroupActorStillNotifiesBlocker(): void
    {
        $admin = UserFactory::createOne();
        $blocker = UserFactory::createOne();
        $community = $this->community('ban-grp-staff', $blocker);
        CommunityMemberFactory::createAdminForCommunity($admin, $community);
        $group = UserGroupFactory::createInCommunity($community);
        $this->blockDirectly($blocker, $admin);

        $this->jsonClient($admin)->request('POST', '/api/v1/communities/ban-grp-staff/groups/'.$group->getIdentifier().'/members', ['json' => ['userId' => $blocker->getId()]]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $this->countNotifications($blocker, NotificationType::GroupAdded));
    }

    public function testBlockedChannelModeratorGrantingAccessDoesNotNotifyBlocker(): void
    {
        $mod = UserFactory::createOne();
        $blocker = UserFactory::createOne();
        $community = $this->community('ban-ch-mod', $mod, $blocker);
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'ban-ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($mod, $channel, ChannelRole::Moderator);
        $this->blockDirectly($blocker, $mod);

        $this->jsonClient($mod)->request('POST', '/api/v1/communities/ban-ch-mod/channels/ban-ch/members', ['json' => ['userId' => $blocker->getId()]]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(0, $this->countNotifications($blocker, NotificationType::ChannelAccess));
    }

    public function testStaffChannelActorStillNotifiesBlocker(): void
    {
        $admin = UserFactory::createOne();
        $blocker = UserFactory::createOne();
        $community = $this->community('ban-ch-staff', $blocker);
        CommunityMemberFactory::createAdminForCommunity($admin, $community);
        ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'ban-ch2'])->create();
        $this->blockDirectly($blocker, $admin);

        $this->jsonClient($admin)->request('POST', '/api/v1/communities/ban-ch-staff/channels/ban-ch2/members', ['json' => ['userId' => $blocker->getId()]]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $this->countNotifications($blocker, NotificationType::ChannelAccess));
    }

    /** @return list<string> */
    private function listedTypes(User $user, string $community): array
    {
        $data = $this->jsonClient($user)->request('GET', "/api/v1/communities/{$community}/notifications")->toArray();
        self::assertResponseIsSuccessful();

        return array_values(array_map(static fn (array $n): string => $n['type'], $data['hydra:member']));
    }

    private function unread(User $user, Community $community): int
    {
        $data = $this->jsonClient($user)->request('GET', '/api/v1/notifications/unread-counts')->toArray();

        return $data['counts'][(string) $community->getId()] ?? 0;
    }

    private function setBlock(User $blocker, User $target, bool $on): void
    {
        $client = $this->plainJsonClient($blocker);
        if ($on) {
            $client->request('POST', '/api/v1/me/blocks', ['json' => ['userId' => $target->getId()]]);
            self::assertResponseStatusCodeSame(201);
        } else {
            $client->request('DELETE', '/api/v1/me/blocks/'.$target->getId());
            self::assertResponseStatusCodeSame(204);
        }
    }

    private function digestCount(): int
    {
        $svc = static::getContainer()->get(EmailDigestServiceInterface::class);
        \assert($svc instanceof EmailDigestServiceInterface);

        return $svc->dispatchDue();
    }

    private function grantBoth(User $actor, string $community, string $groupIdentifier, string $channel, User $target): void
    {
        $client = $this->jsonClient($actor);
        $client->request('POST', "/api/v1/communities/{$community}/groups/{$groupIdentifier}/members", ['json' => ['userId' => $target->getId()]]);
        self::assertResponseStatusCodeSame(201);
        $client->request('POST', "/api/v1/communities/{$community}/channels/{$channel}/members", ['json' => ['userId' => $target->getId()]]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testAccessNotificationsHiddenWhenRecipientBlocksActorLater(): void
    {
        $actor = UserFactory::createOne();
        $recipient = UserFactory::createOne(['emailNotifications' => true]);
        $community = $this->community('ban-late', $actor, $recipient);
        $group = UserGroupFactory::new()->inCommunity($community)->withOwner($actor)->create();
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'late-ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($actor, $channel, ChannelRole::Moderator);
        $this->grantBoth($actor, 'ban-late', (string) $group->getIdentifier(), 'late-ch', $recipient);

        self::assertCount(2, $this->listedTypes($recipient, 'ban-late'));
        self::assertSame(2, $this->unread($recipient, $community));

        $this->setBlock($recipient, $actor, true);
        self::assertSame([], $this->listedTypes($recipient, 'ban-late'));
        self::assertSame(0, $this->unread($recipient, $community));
        self::assertSame(0, $this->digestCount());

        $this->setBlock($recipient, $actor, false);
        self::assertCount(2, $this->listedTypes($recipient, 'ban-late'));
        self::assertSame(2, $this->unread($recipient, $community));
    }

    public function testStaffActorAccessNotificationsStayVisibleAfterBlock(): void
    {
        $admin = UserFactory::createOne();
        $recipient = UserFactory::createOne();
        $community = $this->community('ban-late-staff', $recipient);
        CommunityMemberFactory::createAdminForCommunity($admin, $community);
        $group = UserGroupFactory::createInCommunity($community);
        ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'late-ch2'])->create();
        $this->grantBoth($admin, 'ban-late-staff', (string) $group->getIdentifier(), 'late-ch2', $recipient);

        $this->blockDirectly($recipient, $admin);

        self::assertCount(2, $this->listedTypes($recipient, 'ban-late-staff'));
        self::assertSame(2, $this->unread($recipient, $community));
    }
}
