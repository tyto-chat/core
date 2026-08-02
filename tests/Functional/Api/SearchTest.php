<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Service\Search\SearchServiceInterface;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\ConversationFactory;
use App\Tests\Factory\ConversationMemberFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use App\Tests\Stub\InMemorySearchService;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class SearchTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function search(): InMemorySearchService
    {
        $svc = static::getContainer()->get(SearchServiceInterface::class);
        \assert($svc instanceof InMemorySearchService);

        return $svc;
    }

    public function testChannelSearchReturnsMatches(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c-search')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);

        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $hit = MessageFactory::new()->inPage($page)->byUser($user)->withText('hello world')->create();
        MessageFactory::new()->inPage($page)->byUser($user)->withText('goodbye')->create();

        $this->search()->indexMessage($hit);
        // Second one too, to prove only matches come back.
        foreach (MessageFactory::all() as $m) {
            $this->search()->indexMessage($m);
        }

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/c-search/channels/general/search?q=hello');

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertSame(1, $body['total']);
        self::assertSame($hit->getId(), $body['hits'][0]['messageId']);
        self::assertSame('/api/v1/messages/'.$hit->getId(), $body['hits'][0]['messageIri']);
        self::assertStringContainsString('<mark>', $body['hits'][0]['snippet']);
        // The embedded message keeps its JSON-LD @id — the client renders the
        // hit via message["@id"], so this is a hard contract.
        self::assertSame('/api/v1/messages/'.$hit->getId(), $body['hits'][0]['message']['@id']);
    }

    public function testChannelSearchRejectsTooShortQuery(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c-short')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/c-short/channels/general/search?q=a');

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertSame(0, $body['total']);
        self::assertSame([], $body['hits']);
    }

    public function testChannelSearchDeniedForNonMemberOfPrivate(): void
    {
        $owner = UserFactory::createOne();
        $stranger = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c-priv')->create();
        CommunityMemberFactory::createForUserAndCommunity($owner, $community);
        CommunityMemberFactory::createForUserAndCommunity($stranger, $community);
        $channel = ChannelFactory::new()->inCommunity($community)
            ->with(['identifier' => 'private-ch', 'private' => true])->create();
        ChannelMemberFactory::createForUserAndChannel($owner, $channel);

        $this->jsonClient($stranger)->request('GET', '/api/v1/communities/c-priv/channels/private-ch/search?q=hello');

        self::assertResponseStatusCodeSame(404);
    }

    public function testChannelSearchDeniedForNonMemberOfPrivateCommunity(): void
    {
        // Even a public channel must not be searchable by non-members of a private community.
        $stranger = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('c-priv-com')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $this->jsonClient($stranger)->request('GET', '/api/v1/communities/c-priv-com/channels/general/search?q=hello');

        self::assertResponseStatusCodeSame(404);
    }

    public function testChannelSearchFiltersByAuthor(): void
    {
        $alice = UserFactory::createOne();
        $bob = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c-auth')->create();
        CommunityMemberFactory::createForUserAndCommunity($alice, $community);
        CommunityMemberFactory::createForUserAndCommunity($bob, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $aliceMsg = MessageFactory::new()->inPage($page)->byUser($alice)->withText('pizza time')->create();
        $bobMsg = MessageFactory::new()->inPage($page)->byUser($bob)->withText('pizza party')->create();

        $this->search()->indexMessage($aliceMsg);
        $this->search()->indexMessage($bobMsg);

        $aliceId = $alice->getId();
        $response = $this->jsonClient($alice)->request(
            'GET',
            '/api/v1/communities/c-auth/channels/general/search?q=pizza&authorId='.$aliceId,
        );

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertSame(1, $body['total']);
        self::assertSame($aliceId, $body['hits'][0]['authorId']);
    }

    public function testConversationSearchReturnsMatches(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $conversation = ConversationFactory::new()->withParticipants([$a, $b])->create();
        ConversationMemberFactory::createForUserAndConversation($a, $conversation);
        ConversationMemberFactory::createForUserAndConversation($b, $conversation);

        $page = MessagePageFactory::new()->forConversation($conversation)->create();
        $hit = MessageFactory::new()->inPage($page)->byUser($a)->withText('meet me at the cafe')->create();
        MessageFactory::new()->inPage($page)->byUser($b)->withText('unrelated')->create();

        foreach (MessageFactory::all() as $m) {
            $this->search()->indexMessage($m);
        }

        $response = $this->jsonClient($a)->request(
            'GET',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/search?q=cafe',
        );

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertSame(1, $body['total']);
        self::assertSame($hit->getId(), $body['hits'][0]['messageId']);
        self::assertSame($conversation->getIdentifier(), $body['hits'][0]['conversationIdentifier']);
    }

    public function testConversationSearchDeniedForNonMember(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $stranger = UserFactory::createOne();
        $conversation = ConversationFactory::new()->withParticipants([$a, $b])->create();
        ConversationMemberFactory::createForUserAndConversation($a, $conversation);
        ConversationMemberFactory::createForUserAndConversation($b, $conversation);

        $this->jsonClient($stranger)->request(
            'GET',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/search?q=cafe',
        );

        self::assertResponseStatusCodeSame(403);
    }
}
