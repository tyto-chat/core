<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Channel;
use App\Entity\User;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ChannelUnreadTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /**
     * @param string[] $expected
     */
    private function assertUnreadIs(User $user, string $communityIdentifier, array $expected): void
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

    private function postMessage(Channel $channel, User $author): void
    {
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        MessageFactory::new()->inPage($page)->byUser($author)->create();
    }

    public function testChannelWithMessageFromAnotherUserIsUnread(): void
    {
        $user = UserFactory::createOne();
        $other = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c1')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $this->postMessage($channel, $other);

        $this->assertUnreadIs($user, 'c1', ['general']);
    }

    public function testPublicChannelUnreadWithoutMembershipRow(): void
    {
        // Public channels have no ChannelMember row; unread must still surface.
        $user = UserFactory::createOne();
        $other = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c2')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'public-ch'])->create();

        $this->postMessage($channel, $other);

        $this->assertUnreadIs($user, 'c2', ['public-ch']);
    }

    public function testOwnMessagesDoNotMarkUnread(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c3')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $this->postMessage($channel, $user);

        $this->assertUnreadIs($user, 'c3', []);
    }

    public function testDeletedMessagesDoNotMarkUnread(): void
    {
        $user = UserFactory::createOne();
        $other = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c4')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $page = MessagePageFactory::new()->forChannel($channel)->create();
        MessageFactory::new()->inPage($page)->byUser($other)->deleted()->create();

        $this->assertUnreadIs($user, 'c4', []);
    }

    public function testPrivateChannelExcludedForNonMember(): void
    {
        $user = UserFactory::createOne();
        $other = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c5')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'secret'])->create();

        $this->postMessage($channel, $other);

        $this->assertUnreadIs($user, 'c5', []);
    }

    public function testPrivateChannelIncludedForMember(): void
    {
        $user = UserFactory::createOne();
        $other = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c6')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'secret'])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);

        $this->postMessage($channel, $other);

        $this->assertUnreadIs($user, 'c6', ['secret']);
    }

    public function testMarkReadClearsUnread(): void
    {
        $user = UserFactory::createOne();
        $other = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c7')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $this->postMessage($channel, $other);
        $this->assertUnreadIs($user, 'c7', ['general']);

        $this->plainJsonClient($user)->request('POST', '/api/v1/communities/c7/channels/general/mark-read');
        self::assertResponseStatusCodeSame(204);

        $this->assertUnreadIs($user, 'c7', []);
    }

    public function testMarkReadRequiresChannelAccess(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c8')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'secret'])->create();

        $this->plainJsonClient($user)->request('POST', '/api/v1/communities/c8/channels/secret/mark-read');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnreadRequiresAuthentication(): void
    {
        CommunityFactory::new()->withIdentifier('c9')->create();

        $this->plainJsonClient()->request('GET', '/api/v1/communities/c9/unread-channels');

        self::assertResponseStatusCodeSame(401);
    }
}
