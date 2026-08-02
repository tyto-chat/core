<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Channel;
use App\Entity\User;
use App\Enum\Notification\NotificationType;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\ConversationFactory;
use App\Tests\Factory\ConversationMemberFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\NotificationFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class MarkAllReadTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function postMessage(Channel $channel, User $author): void
    {
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        MessageFactory::new()->inPage($page)->byUser($author)->create();
    }

    /**
     * @param string[] $expected
     */
    private function assertUnreadChannelsAre(User $user, string $communityIdentifier, array $expected): void
    {
        $response = $this->plainJsonClient($user)->request(
            'GET',
            "/api/v1/communities/{$communityIdentifier}/unread-channels",
        );
        self::assertResponseIsSuccessful();
        $unread = $response->toArray()['unread'];
        sort($unread);
        sort($expected);
        self::assertSame($expected, $unread);
    }

    public function testCommunityMarkAllReadClearsAllChannelsInCommunity(): void
    {
        $user = UserFactory::createOne();
        $other = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cmar1')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $a = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'a'])->create();
        $b = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'b'])->create();

        $this->postMessage($a, $other);
        $this->postMessage($b, $other);
        $this->assertUnreadChannelsAre($user, 'cmar1', ['a', 'b']);

        $this->plainJsonClient($user)->request('POST', '/api/v1/communities/cmar1/mark-all-read');
        self::assertResponseStatusCodeSame(204);

        $this->assertUnreadChannelsAre($user, 'cmar1', []);
    }

    public function testCommunityMarkAllReadAlsoClearsCommunityNotifications(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cmar2')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        NotificationFactory::new()->forRecipient($user)->inCommunity($community)->create();
        NotificationFactory::new()->forRecipient($user)->inCommunity($community)->create();

        $this->plainJsonClient($user)->request('POST', '/api/v1/communities/cmar2/mark-all-read');
        self::assertResponseStatusCodeSame(204);

        $response = $this->plainJsonClient($user)->request('GET', '/api/v1/notifications/unread-counts');
        $counts = $response->toArray()['counts'];
        self::assertArrayNotHasKey((string) $community->getId(), $counts);
    }

    public function testCommunityMarkAllReadRequiresCommunityView(): void
    {
        $caller = UserFactory::createOne();
        $owner = UserFactory::createOne();
        // Private community, caller not a member → COMMUNITY_VIEW denied.
        $community = CommunityFactory::new()->withIdentifier('cmar3')->private()->create();
        CommunityMemberFactory::createForUserAndCommunity($owner, $community);

        $this->plainJsonClient($caller)->request('POST', '/api/v1/communities/cmar3/mark-all-read');
        self::assertResponseStatusCodeSame(404);
    }

    public function testConversationsMarkAllReadSetsLastReadAtOnEveryMembership(): void
    {
        $user = UserFactory::createOne();
        $peer = UserFactory::createOne();
        $convA = ConversationFactory::createOne();
        $convB = ConversationFactory::createOne();
        ConversationMemberFactory::createForUserAndConversation($user, $convA);
        ConversationMemberFactory::createForUserAndConversation($peer, $convA);
        ConversationMemberFactory::createForUserAndConversation($user, $convB);
        ConversationMemberFactory::createForUserAndConversation($peer, $convB);

        // Buffer for DB second-precision rounding vs PHP microseconds.
        $before = new \DateTimeImmutable('-1 second');

        $this->plainJsonClient($user)->request('POST', '/api/v1/me/conversations/mark-all-read');
        self::assertResponseStatusCodeSame(204);

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->clear();
        $convAId = $convA->getId();
        $convBId = $convB->getId();
        $userId = $user->getId();
        $memberA = $em->getRepository(\App\Entity\ConversationMember::class)
            ->findOneBy(['conversation' => $convAId, 'user' => $userId]);
        $memberB = $em->getRepository(\App\Entity\ConversationMember::class)
            ->findOneBy(['conversation' => $convBId, 'user' => $userId]);
        self::assertNotNull($memberA?->getLastReadAt());
        self::assertNotNull($memberB?->getLastReadAt());
        self::assertGreaterThanOrEqual($before, $memberA->getLastReadAt());
        self::assertGreaterThanOrEqual($before, $memberB->getLastReadAt());
    }

    public function testConversationsMarkAllReadAlsoClearsDmNotifications(): void
    {
        $user = UserFactory::createOne();
        // DM notification has community = null
        NotificationFactory::new()->forRecipient($user)->with(['community' => null, 'type' => NotificationType::DmMessage])->create();
        NotificationFactory::new()->forRecipient($user)->with(['community' => null, 'type' => NotificationType::DmMessage])->create();

        $this->plainJsonClient($user)->request('POST', '/api/v1/me/conversations/mark-all-read');
        self::assertResponseStatusCodeSame(204);

        $response = $this->plainJsonClient($user)->request('GET', '/api/v1/notifications/unread-counts');
        $counts = $response->toArray()['counts'];
        self::assertArrayNotHasKey('dm', $counts);
    }

    public function testGlobalMarkAllReadClearsChannelsConversationsAndNotifications(): void
    {
        $user = UserFactory::createOne();
        $other = UserFactory::createOne();

        $c1 = CommunityFactory::new()->withIdentifier('gmar1')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $c1);
        $ch1 = ChannelFactory::new()->inCommunity($c1)->with(['identifier' => 'g1'])->create();
        $this->postMessage($ch1, $other);

        $c2 = CommunityFactory::new()->withIdentifier('gmar2')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $c2);
        $ch2 = ChannelFactory::new()->inCommunity($c2)->with(['identifier' => 'g2'])->create();
        $this->postMessage($ch2, $other);

        $conv = ConversationFactory::createOne();
        ConversationMemberFactory::createForUserAndConversation($user, $conv);
        ConversationMemberFactory::createForUserAndConversation($other, $conv);

        NotificationFactory::new()->forRecipient($user)->inCommunity($c1)->create();
        NotificationFactory::new()->forRecipient($user)->with(['community' => null, 'type' => NotificationType::DmMessage])->create();

        $this->plainJsonClient($user)->request('POST', '/api/v1/me/mark-all-read');
        self::assertResponseStatusCodeSame(204);

        $this->assertUnreadChannelsAre($user, 'gmar1', []);
        $this->assertUnreadChannelsAre($user, 'gmar2', []);

        $response = $this->plainJsonClient($user)->request('GET', '/api/v1/notifications/unread-counts');
        self::assertSame([], (array) $response->toArray()['counts']);
    }

    public function testAllEndpointsRequireAuthentication(): void
    {
        CommunityFactory::new()->withIdentifier('mar-anon')->create();

        $this->plainJsonClient()->request('POST', '/api/v1/communities/mar-anon/mark-all-read');
        self::assertResponseStatusCodeSame(401);

        $this->plainJsonClient()->request('POST', '/api/v1/me/conversations/mark-all-read');
        self::assertResponseStatusCodeSame(401);

        $this->plainJsonClient()->request('POST', '/api/v1/me/mark-all-read');
        self::assertResponseStatusCodeSame(401);
    }
}
