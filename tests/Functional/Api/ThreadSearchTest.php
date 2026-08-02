<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Message;
use App\Enum\Message\MessageKind;
use App\Repository\MessageRepository;
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
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Thread replies are indexed like roots (MessageDocumentBuilder::isIndexable
 * no longer excludes `parent !== null`) — the doc inherits its container +
 * pageNumber through the root's page, so channel/community/conversation
 * search pick them up with no query changes. Deleted + system messages stay
 * excluded either way.
 */
final class ThreadSearchTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function search(): InMemorySearchService
    {
        $svc = static::getContainer()->get(SearchServiceInterface::class);
        \assert($svc instanceof InMemorySearchService);

        return $svc;
    }

    private function messageRepository(): MessageRepository
    {
        return static::getContainer()->get(MessageRepository::class);
    }

    /**
     * Fetch the freshly-persisted reply entity by id, mirroring how the
     * async indexing path re-resolves an entity from the repository after
     * the API request (and its own EM) has committed.
     */
    private function reloadMessage(string $id): Message
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $message = $this->messageRepository()->find($id);
        \assert($message instanceof Message);

        return $message;
    }

    public function testReplyFindableViaChannelSearch(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ts-chan')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $root = MessageFactory::new()->inPage($page)->byUser($user)->withText('root message')->create();

        $created = $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'unique needle text'],
        ])->toArray();
        $replyId = basename((string) $created['@id']);

        // Index both root and reply — decoy root proves the hit is the
        // reply specifically, not an accidental match on the root.
        $this->search()->indexMessage($this->reloadMessage($root->getId()));
        $reply = $this->reloadMessage($replyId);
        $this->search()->indexMessage($reply);

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/ts-chan/channels/general/search?q=needle');

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertSame(1, $body['total']);
        self::assertSame($replyId, $body['hits'][0]['messageId']);
        self::assertSame($root->getPageNumber(), $body['hits'][0]['pageNumber']);
    }

    public function testReplyFindableViaCommunitySearch(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ts-comm')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $root = MessageFactory::new()->inPage($page)->byUser($user)->withText('root message')->create();

        $created = $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'needle across community'],
        ])->toArray();
        $replyId = basename((string) $created['@id']);

        $this->search()->indexMessage($this->reloadMessage($replyId));

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/ts-comm/search?q=needle');

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertSame(1, $body['total']);
        self::assertSame($replyId, $body['hits'][0]['messageId']);
        self::assertSame('general', $body['hits'][0]['channelIdentifier']);
    }

    public function testPrivateChannelReplyHiddenFromNonMembersInCommunitySearch(): void
    {
        $member = UserFactory::createOne();
        $insider = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ts-priv')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        CommunityMemberFactory::createForUserAndCommunity($insider, $community);
        $secret = ChannelFactory::new()->inCommunity($community)
            ->with(['identifier' => 'secret', 'private' => true])->create();
        ChannelMemberFactory::createForUserAndChannel($insider, $secret);

        $page = MessagePageFactory::new()->forChannel($secret)->create();
        $root = MessageFactory::new()->inPage($page)->byUser($insider)->withText('root secret')->create();

        $created = $this->jsonClient($insider)->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'needle secret reply'],
        ])->toArray();
        $replyId = basename((string) $created['@id']);
        $this->search()->indexMessage($this->reloadMessage($replyId));

        // Negative: plain community member (no channel membership) must not
        // see the reply in a community-wide search.
        $memberBody = $this->jsonClient($member)->request('GET', '/api/v1/communities/ts-priv/search?q=needle')->toArray();
        self::assertSame(0, $memberBody['total']);
        self::assertSame([], $memberBody['hits']);

        // Positive control: the channel member (insider) does see it.
        $insiderBody = $this->jsonClient($insider)->request('GET', '/api/v1/communities/ts-priv/search?q=needle')->toArray();
        self::assertSame(1, $insiderBody['total']);
        self::assertSame($replyId, $insiderBody['hits'][0]['messageId']);
    }

    public function testDmReplyFindableViaConversationSearchForParticipantsOnly(): void
    {
        $alice = UserFactory::createOne();
        $bob = UserFactory::createOne();
        $stranger = UserFactory::createOne();
        $conversation = ConversationFactory::new()->withParticipants([$alice, $bob])->create();
        ConversationMemberFactory::createForUserAndConversation($alice, $conversation);
        ConversationMemberFactory::createForUserAndConversation($bob, $conversation);
        $page = MessagePageFactory::new()->forConversation($conversation)->create();
        $root = MessageFactory::new()->inPage($page)->byUser($bob)->withText('root dm')->create();

        $created = $this->jsonClient($alice)->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'needle in a dm reply'],
        ])->toArray();
        $replyId = basename((string) $created['@id']);
        $this->search()->indexMessage($this->reloadMessage($replyId));

        // Positive: a participant finds the reply via conversation search.
        $body = $this->jsonClient($bob)->request(
            'GET',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/search?q=needle',
        )->toArray();
        self::assertSame(1, $body['total']);
        self::assertSame($replyId, $body['hits'][0]['messageId']);
        self::assertSame($conversation->getIdentifier(), $body['hits'][0]['conversationIdentifier']);

        // Negative: a non-participant is denied outright (existing gate) —
        // never even gets to see an empty result, let alone the reply.
        $this->jsonClient($stranger)->request(
            'GET',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/search?q=needle',
        );
        self::assertResponseStatusCodeSame(403);
    }

    public function testDeletedReplyRemovedFromIndex(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ts-del')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $root = MessageFactory::new()->inPage($page)->byUser($user)->withText('root message')->create();

        $created = $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'needle to be deleted'],
        ])->toArray();
        $replyId = basename((string) $created['@id']);
        $this->search()->indexMessage($this->reloadMessage($replyId));

        // Positive control: indexed before deletion, the reply is findable.
        $before = $this->jsonClient($user)->request('GET', '/api/v1/communities/ts-del/channels/general/search?q=needle')->toArray();
        self::assertSame(1, $before['total']);
        self::assertSame($replyId, $before['hits'][0]['messageId']);

        $this->jsonClient($user)->request('DELETE', '/api/v1/messages/'.$replyId);
        self::assertResponseStatusCodeSame(204);

        // Mirror the async re-index path: reload the now-soft-deleted entity
        // and re-run indexMessage — isIndexable() flips false, doc removed.
        $this->search()->indexMessage($this->reloadMessage($replyId));

        $after = $this->jsonClient($user)->request('GET', '/api/v1/communities/ts-del/channels/general/search?q=needle')->toArray();
        self::assertSame(0, $after['total']);
        self::assertSame([], $after['hits']);
    }

    public function testSystemMessagesStillExcluded(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ts-sys')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);
        $page = MessagePageFactory::new()->forChannel($channel)->create();

        $systemMessage = MessageFactory::new()->inPage($page)->byUser($user)
            ->withText('needle system announcement')
            ->with(['kind' => MessageKind::System])
            ->create();
        // Positive control: an ordinary message with the same search term,
        // in the same channel, proves the query itself would match.
        $standardMessage = MessageFactory::new()->inPage($page)->byUser($user)
            ->withText('needle standard message')
            ->create();

        $this->search()->indexMessage($systemMessage);
        $this->search()->indexMessage($standardMessage);

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/ts-sys/channels/general/search?q=needle');

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertSame(1, $body['total']);
        self::assertSame($standardMessage->getId(), $body['hits'][0]['messageId']);
        self::assertNotContains($systemMessage->getId(), array_column($body['hits'], 'messageId'));
    }
}
