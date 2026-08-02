<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\Notification\NotificationType;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\NotificationFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class NotificationTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testUserSeesOnlyTheirNotifications(): void
    {
        $user = UserFactory::createOne();
        $other = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('notif-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        NotificationFactory::new()->forRecipient($user)->inCommunity($community)->many(2)->create();
        NotificationFactory::new()->forRecipient($other)->inCommunity($community)->create();

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/notif-c/notifications');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertCount(2, $data['hydra:member']);
    }

    public function testAnonymousCannotGetNotifications(): void
    {
        CommunityFactory::new()->withIdentifier('notif-anon')->create();

        $this->jsonClient()->request('GET', '/api/v1/communities/notif-anon/notifications');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRecipientCanMarkNotificationAsRead(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::createOne();
        $notification = NotificationFactory::new()->forRecipient($user)->inCommunity($community)->create();

        $this->jsonClient($user)->request('PATCH', '/api/v1/notifications/'.$notification->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['isRead' => true],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['isRead' => true]);
    }

    public function testNonRecipientCannotMarkNotificationAsRead(): void
    {
        $owner = UserFactory::createOne();
        $other = UserFactory::createOne();
        $community = CommunityFactory::createOne();
        $notification = NotificationFactory::new()->forRecipient($owner)->inCommunity($community)->create();

        $this->jsonClient($other)->request('PATCH', '/api/v1/notifications/'.$notification->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['isRead' => true],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAuthenticatedUserCanGetUnreadCounts(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::createOne();
        NotificationFactory::new()->forRecipient($user)->inCommunity($community)->createMany(2);

        $response = $this->jsonClient($user)->request('GET', '/api/v1/notifications/unread-counts');

        self::assertResponseIsSuccessful();
        $data = $response->toArray(false);
        self::assertArrayHasKey('counts', $data);
    }

    public function testAnonymousCannotGetUnreadCounts(): void
    {
        $this->jsonClient()->request('GET', '/api/v1/notifications/unread-counts');

        self::assertResponseStatusCodeSame(401);
    }

    public function testMarkAllReadSetsIsReadOnAllUserNotifications(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('mark-all-c')->create();
        NotificationFactory::new()->forRecipient($user)->inCommunity($community)->createMany(3);

        $this->jsonClient($user)->request('POST', '/api/v1/communities/mark-all-c/notifications/mark-all-read');

        self::assertResponseStatusCodeSame(204);
    }

    public function testMarkAllReadReturns401ForAnonymous(): void
    {
        CommunityFactory::new()->withIdentifier('mark-anon')->create();

        $this->jsonClient()->request('POST', '/api/v1/communities/mark-anon/notifications/mark-all-read');

        self::assertResponseStatusCodeSame(401);
    }

    public function testMeNotificationsReturnsCommunityLessRowsOnly(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('dm-notif-c')->create();
        NotificationFactory::new()->forRecipient($user)->inCommunity($community)->create();
        $this->persistDmNotification($user, 'conv-1');
        $this->persistDmNotification($user, 'conv-1');

        $response = $this->jsonClient($user)->request('GET', '/api/v1/me/notifications');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertCount(2, $data['hydra:member']);
    }

    public function testMeNotificationsAnonymousReturns401(): void
    {
        $this->jsonClient()->request('GET', '/api/v1/me/notifications');
        self::assertResponseStatusCodeSame(401);
    }

    public function testMarkAllDmReadEndpoint(): void
    {
        $user = UserFactory::createOne();
        $this->persistDmNotification($user, 'conv-mark');
        $this->persistDmNotification($user, 'conv-mark');

        $this->jsonClient($user)->request('POST', '/api/v1/me/notifications/mark-all-read');

        self::assertResponseStatusCodeSame(204);
    }

    public function testUnreadCountsReportsDmBucket(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('unread-mix-c')->create();
        NotificationFactory::new()->forRecipient($user)->inCommunity($community)->create();
        $this->persistDmNotification($user, 'conv-unread');
        $this->persistDmNotification($user, 'conv-unread');

        $response = $this->jsonClient($user)->request('GET', '/api/v1/notifications/unread-counts');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertSame(2, $data['counts']['dm']);
        self::assertSame(1, $data['counts'][(string) $community->getId()]);
    }

    private function persistDmNotification(\App\Entity\User $user, string $conversationIdentifier): void
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $notif = new \App\Entity\Notification();
        $notif->setRecipient($user);
        $notif->setType(NotificationType::DmMessage);
        $notif->setConversationIdentifier($conversationIdentifier);
        $notif->setAuthorName('Author');
        $em->persist($notif);
        $em->flush();
    }
}
