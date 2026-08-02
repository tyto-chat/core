<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\Channel\ChannelRole;
use App\Service\Search\SearchServiceInterface;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\GroupChannelPermissionFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\UserGroupFactory;
use App\Tests\Factory\UserGroupMemberFactory;
use App\Tests\Functional\ApiTestCase;
use App\Tests\Stub\InMemorySearchService;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class CommunitySearchTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function search(): InMemorySearchService
    {
        $svc = static::getContainer()->get(SearchServiceInterface::class);
        \assert($svc instanceof InMemorySearchService);

        return $svc;
    }

    public function testCommunitySearchSpansChannels(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cw-span')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $general = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();
        $random = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'random'])->create();

        $pageA = MessagePageFactory::new()->forChannel($general)->create();
        $pageB = MessagePageFactory::new()->forChannel($random)->create();
        $hitA = MessageFactory::new()->inPage($pageA)->byUser($user)->withText('needle in general')->create();
        $hitB = MessageFactory::new()->inPage($pageB)->byUser($user)->withText('needle in random')->create();
        MessageFactory::new()->inPage($pageA)->byUser($user)->withText('irrelevant')->create();
        foreach (MessageFactory::all() as $m) {
            $this->search()->indexMessage($m);
        }

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/cw-span/search?q=needle');

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertSame(2, $body['total']);
        $byId = array_column($body['hits'], null, 'messageId');
        self::assertSame('general', $byId[$hitA->getId()]['channelIdentifier']);
        self::assertSame('random', $byId[$hitB->getId()]['channelIdentifier']);
        self::assertSame('cw-span', $byId[$hitA->getId()]['communityIdentifier']);
        self::assertSame('/api/v1/messages/'.$hitA->getId(), $byId[$hitA->getId()]['message']['@id']);
        self::assertStringContainsString('<mark>', $byId[$hitA->getId()]['snippet']);
    }

    public function testCommunitySearchRejectsTooShortQuery(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cw-short')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/cw-short/search?q=a');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $response->toArray()['total']);
    }

    public function testCommunitySearchFiltersByAuthor(): void
    {
        $alice = UserFactory::createOne();
        $bob = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cw-author')->create();
        CommunityMemberFactory::createForUserAndCommunity($alice, $community);
        CommunityMemberFactory::createForUserAndCommunity($bob, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        MessageFactory::new()->inPage($page)->byUser($alice)->withText('needle from alice')->create();
        $bobMsg = MessageFactory::new()->inPage($page)->byUser($bob)->withText('needle from bob')->create();
        foreach (MessageFactory::all() as $m) {
            $this->search()->indexMessage($m);
        }

        $response = $this->jsonClient($alice)->request(
            'GET',
            '/api/v1/communities/cw-author/search?q=needle&authorId='.$bob->getId(),
        );

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertSame(1, $body['total']);
        self::assertSame($bobMsg->getId(), $body['hits'][0]['messageId']);
    }

    public function testCommunitySearchEmptyWhenNoVisibleChannels(): void
    {
        // Community whose only text channel is private; caller is a plain
        // member with no channel membership → visible set empty → 200 empty.
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cw-empty')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        ChannelFactory::new()->inCommunity($community)
            ->with(['identifier' => 'secret', 'private' => true])->create();

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/cw-empty/search?q=needle');

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertSame(0, $body['total']);
        self::assertSame([], $body['hits']);
    }

    public function testPrivateChannelHitsHiddenFromNonChannelMembers(): void
    {
        $member = UserFactory::createOne();
        $insider = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cw-priv')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        CommunityMemberFactory::createForUserAndCommunity($insider, $community);
        $public = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();
        $secret = ChannelFactory::new()->inCommunity($community)
            ->with(['identifier' => 'secret', 'private' => true])->create();
        ChannelMemberFactory::createForUserAndChannel($insider, $secret);

        $pagePub = MessagePageFactory::new()->forChannel($public)->create();
        $pageSec = MessagePageFactory::new()->forChannel($secret)->create();
        $pubMsg = MessageFactory::new()->inPage($pagePub)->byUser($insider)->withText('needle public')->create();
        $secMsg = MessageFactory::new()->inPage($pageSec)->byUser($insider)->withText('needle secret')->create();
        foreach (MessageFactory::all() as $m) {
            $this->search()->indexMessage($m);
        }

        $body = $this->jsonClient($member)->request('GET', '/api/v1/communities/cw-priv/search?q=needle')->toArray();
        self::assertSame(1, $body['total']);
        self::assertSame($pubMsg->getId(), $body['hits'][0]['messageId']);

        $body = $this->jsonClient($insider)->request('GET', '/api/v1/communities/cw-priv/search?q=needle')->toArray();
        self::assertSame(2, $body['total']);
        self::assertContains($secMsg->getId(), array_column($body['hits'], 'messageId'));
    }

    public function testGroupGrantedUserSeesPrivateChannelHits(): void
    {
        $granted = UserFactory::createOne();
        $author = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cw-group')->create();
        CommunityMemberFactory::createForUserAndCommunity($granted, $community);
        CommunityMemberFactory::createForUserAndCommunity($author, $community);
        $secret = ChannelFactory::new()->inCommunity($community)
            ->with(['identifier' => 'secret', 'private' => true])->create();
        ChannelMemberFactory::createForUserAndChannel($author, $secret);

        $group = UserGroupFactory::new()->inCommunity($community)->withOwner($author)->create();
        UserGroupMemberFactory::createForUserAndGroup($granted, $group);
        GroupChannelPermissionFactory::createForGroupAndChannel($group, $secret, ChannelRole::Member);

        $page = MessagePageFactory::new()->forChannel($secret)->create();
        $msg = MessageFactory::new()->inPage($page)->byUser($author)->withText('needle via group')->create();
        $this->search()->indexMessage($msg);

        $body = $this->jsonClient($granted)->request('GET', '/api/v1/communities/cw-group/search?q=needle')->toArray();

        self::assertSame(1, $body['total']);
        self::assertSame($msg->getId(), $body['hits'][0]['messageId']);
    }

    public function testPrivateCommunityHiddenFromNonMembers(): void
    {
        $stranger = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('cw-privcom')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $this->jsonClient($stranger)->request('GET', '/api/v1/communities/cw-privcom/search?q=needle');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousGetsUnauthorized(): void
    {
        CommunityFactory::new()->withIdentifier('cw-anon')->create();

        static::createClient()->request('GET', '/api/v1/communities/cw-anon/search?q=needle', [
            'headers' => ['Accept' => 'application/ld+json'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testPrivacyFlipAfterIndexingDoesNotLeak(): void
    {
        $member = UserFactory::createOne();
        $author = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cw-flip')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        CommunityMemberFactory::createForUserAndCommunity($author, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'was-public'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $msg = MessageFactory::new()->inPage($page)->byUser($author)->withText('needle flip')->create();
        $this->search()->indexMessage($msg);

        $channel->setPrivate(true);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($channel);
        $em->flush();

        $body = $this->jsonClient($member)->request('GET', '/api/v1/communities/cw-flip/search?q=needle')->toArray();

        self::assertSame(0, $body['total']);
        self::assertSame([], $body['hits']);
    }

    public function testForgedDocFromAnotherCommunityDropped(): void
    {
        // Index poisoning: a doc claims a channelId inside the caller's
        // visible set, but its message id belongs to another community.
        // The shape() re-check must drop it.
        $caller = UserFactory::createOne();
        $communityA = CommunityFactory::new()->withIdentifier('cw-a')->create();
        CommunityMemberFactory::createForUserAndCommunity($caller, $communityA);
        $channelA = ChannelFactory::new()->inCommunity($communityA)->with(['identifier' => 'general'])->create();

        $communityB = CommunityFactory::new()->private()->withIdentifier('cw-b')->create();
        $channelB = ChannelFactory::new()->inCommunity($communityB)
            ->with(['identifier' => 'b-secret', 'private' => true])->create();
        $author = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($author, $communityB);
        ChannelMemberFactory::createForUserAndChannel($author, $channelB);
        $pageB = MessagePageFactory::new()->forChannel($channelB)->create();
        $foreign = MessageFactory::new()->inPage($pageB)->byUser($author)->withText('needle foreign')->create();

        $this->search()->injectDocument([
            'id' => $foreign->getId(),
            'text' => 'needle foreign',
            'authorId' => $author->getId(),
            'authorName' => 'Forged',
            'createdAt' => 100,
            'channelId' => $channelA->getId(),
            'conversationId' => null,
            'pageNumber' => 1,
        ]);

        $body = $this->jsonClient($caller)->request('GET', '/api/v1/communities/cw-a/search?q=needle')->toArray();

        self::assertSame([], $body['hits']);
    }

    public function testCommunityAdminSeesPrivateChannelHitsWithoutMembership(): void
    {
        // ChannelVoter::VIEW grants community admins full access regardless of
        // channel membership — search must mirror that, same as channel view.
        $admin = UserFactory::createOne();
        $author = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cw-admin')->create();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);
        CommunityMemberFactory::createForUserAndCommunity($author, $community);
        $secret = ChannelFactory::new()->inCommunity($community)
            ->with(['identifier' => 'secret', 'private' => true])->create();
        ChannelMemberFactory::createForUserAndChannel($author, $secret);

        $page = MessagePageFactory::new()->forChannel($secret)->create();
        $msg = MessageFactory::new()->inPage($page)->byUser($author)->withText('needle admin')->create();
        $this->search()->indexMessage($msg);

        $body = $this->jsonClient($admin)->request('GET', '/api/v1/communities/cw-admin/search?q=needle')->toArray();

        self::assertSame(1, $body['total']);
        self::assertSame($msg->getId(), $body['hits'][0]['messageId']);
    }
}
