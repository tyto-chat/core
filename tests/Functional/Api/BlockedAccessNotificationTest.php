<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Community;
use App\Entity\Notification;
use App\Entity\User;
use App\Entity\UserBlock;
use App\Enum\Channel\ChannelRole;
use App\Enum\Notification\NotificationType;
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
        $em->persist((new UserBlock())->setBlocker($blocker)->setBlocked($blocked));
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
}
