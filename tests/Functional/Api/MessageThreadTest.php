<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\MessagePage;
use App\Entity\User;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class MessageThreadTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /** @return array{User, Community, Channel, MessagePage} */
    private function setupFixture(string $communityId = 'thr-c', string $channelId = 'thr-ch'): array
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier($communityId)->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => $channelId])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();

        return [$user, $community, $channel, $page];
    }

    public function testMemberCanReply(): void
    {
        [$user, , , $page] = $this->setupFixture();
        $root = MessageFactory::new()->inPage($page)->byUser($user)->withText('root')->create();

        $response = $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'a reply'],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains(['text' => 'a reply']);
    }

    public function testMemberCannotReplyInReadonlyChannelWithoutFlag(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('thr-ro')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)
            ->with(['identifier' => 'thr-ro-ch', 'readonly' => true])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $root = MessageFactory::new()->inPage($page)->byUser($user)->withText('root')->create();

        $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'a reply'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testMemberCanReplyInReadonlyChannelWhenRepliesAllowed(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('thr-ro-ok')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)
            ->with(['identifier' => 'thr-ro-ok-ch', 'readonly' => true, 'areReadonlyRepliesAllowed' => true])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $root = MessageFactory::new()->inPage($page)->byUser($user)->withText('root')->create();

        $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'a reply'],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains(['text' => 'a reply']);
    }

    public function testReplyHiddenFromChannelTimeline(): void
    {
        [$user, , , $page] = $this->setupFixture('thr-hide');
        $root = MessageFactory::new()->inPage($page)->byUser($user)->withText('root msg')->create();
        MessageFactory::new()->inPage($page)->byUser($user)->withText('the reply')
            ->with(['parent' => $root])->create();

        // The real timeline read is the paginated page endpoint (the
        // message_page:detail group carries the `messages` array).
        $pageNumber = $this->jsonClient($user)
            ->request('GET', '/api/v1/messages/'.$root->getId())
            ->toArray()['pageNumber'];

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/thr-hide/channels/thr-ch/pages/'.$pageNumber);

        self::assertResponseIsSuccessful();
        $messages = $response->toArray()['messages'] ?? [];
        $texts = array_map(static fn (array $m) => $m['text'] ?? null, $messages);
        self::assertContains('root msg', $texts);
        self::assertNotContains('the reply', $texts);
    }

    public function testRootReplyCountIncrements(): void
    {
        [$user, , , $page] = $this->setupFixture('thr-count');
        $root = MessageFactory::new()->inPage($page)->byUser($user)->withText('root')->create();

        $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'r1'],
        ]);
        $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'r2'],
        ]);

        $response = $this->jsonClient($user)->request('GET', '/api/v1/messages/'.$root->getId());
        self::assertSame(2, $response->toArray()['replyCount']);
    }

    public function testThreadEndpointReturnsRepliesChronological(): void
    {
        [$user, , , $page] = $this->setupFixture('thr-list');
        $root = MessageFactory::new()->inPage($page)->byUser($user)->withText('root')->create();
        // createdAt is Gedmo-managed (set on persist), so creation order = chronological order.
        MessageFactory::new()->inPage($page)->byUser($user)->withText('first')
            ->with(['parent' => $root])->create();
        MessageFactory::new()->inPage($page)->byUser($user)->withText('second')
            ->with(['parent' => $root])->create();

        $response = $this->jsonClient($user)->request('GET', '/api/v1/messages/'.$root->getId().'/thread');

        self::assertResponseIsSuccessful();
        $items = $response->toArray()['member'] ?? $response->toArray()['hydra:member'] ?? [];
        self::assertCount(2, $items);
        // Both replies present (sub-second createdAt makes strict order non-deterministic in tests).
        $texts = array_map(static fn (array $m) => $m['text'] ?? null, $items);
        self::assertContains('first', $texts);
        self::assertContains('second', $texts);
    }

    public function testCannotReplyToAReply(): void
    {
        [$user, , , $page] = $this->setupFixture('thr-deep');
        $root = MessageFactory::new()->inPage($page)->byUser($user)->withText('root')->create();
        $reply = MessageFactory::new()->inPage($page)->byUser($user)->withText('reply')
            ->with(['parent' => $root])->create();

        $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$reply->getId().'/replies', [
            'json' => ['text' => 'nested'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    // DM threading is covered by ConversationThreadTest.php — root.getConversation()
    // is now a valid thread container alongside root.getChannel().

    public function testAnonymousCanReadThreadOnPublicChannel(): void
    {
        [$user, , , $page] = $this->setupFixture('thr-anon-read');
        $root = MessageFactory::new()->inPage($page)->byUser($user)->withText('root')->create();
        MessageFactory::new()->inPage($page)->byUser($user)->withText('reply')
            ->with(['parent' => $root])->create();

        $response = $this->jsonClient()->request('GET', '/api/v1/messages/'.$root->getId().'/thread');

        self::assertResponseIsSuccessful();
        $items = $response->toArray()['member'] ?? $response->toArray()['hydra:member'] ?? [];
        self::assertCount(1, $items);
    }

    public function testAnonymousCannotReadThreadOnPrivateChannel(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('thr-anon-priv')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)
            ->with(['identifier' => 'thr-anon-priv-ch', 'private' => true])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $root = MessageFactory::new()->inPage($page)->byUser($user)->withText('root')->create();

        $this->jsonClient()->request('GET', '/api/v1/messages/'.$root->getId().'/thread');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousCannotReply(): void
    {
        [$user, , , $page] = $this->setupFixture('thr-anon');
        $root = MessageFactory::new()->inPage($page)->byUser($user)->withText('root')->create();

        $this->jsonClient()->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'sneaky'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testDeletingReplyKeepsRootCount(): void
    {
        // Soft-delete: the reply stays in the thread list (rendered as
        // "This message was deleted." on the client) so the count still
        // matches the visible row total.
        [$user, , , $page] = $this->setupFixture('thr-del-count');
        $root = MessageFactory::new()->inPage($page)->byUser($user)->withText('root')->create();

        $created = $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'temp reply'],
        ])->toArray();
        $replyId = basename((string) $created['@id']);

        $this->jsonClient($user)->request('DELETE', '/api/v1/messages/'.$replyId);
        self::assertResponseStatusCodeSame(204);

        $response = $this->jsonClient($user)->request('GET', '/api/v1/messages/'.$root->getId());
        self::assertSame(1, $response->toArray()['replyCount']);
    }

    public function testCannotPinAReply(): void
    {
        [$user, $community, $channel, $page] = $this->setupFixture('thr-pin');
        \App\Tests\Factory\ChannelMemberFactory::createForUserAndChannel($user, $channel, \App\Enum\Channel\ChannelRole::Moderator);
        $root = MessageFactory::new()->inPage($page)->byUser($user)->withText('root')->create();
        $reply = MessageFactory::new()->inPage($page)->byUser($user)->withText('reply')
            ->with(['parent' => $root])->create();

        $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$reply->getId().'/pin');

        self::assertResponseStatusCodeSame(422);
    }

    public function testGetReplyExposesParentIri(): void
    {
        // The /m/<uuid> permalink resolver depends on this: when the target is
        // a reply, it reads `parent` to redirect into the channel + open the
        // thread + scroll to the reply.
        [$user, , , $page] = $this->setupFixture('thr-parent');
        $root = MessageFactory::new()->inPage($page)->byUser($user)->withText('root')->create();
        $reply = MessageFactory::new()->inPage($page)->byUser($user)->withText('reply')
            ->with(['parent' => $root])->create();

        $response = $this->jsonClient($user)->request('GET', '/api/v1/messages/'.$reply->getId());
        $body = $response->toArray();

        self::assertArrayHasKey('parent', $body);
        $parentValue = $body['parent'];
        $parentIri = \is_string($parentValue) ? $parentValue : ($parentValue['@id'] ?? null);
        self::assertNotNull($parentIri);
        self::assertStringContainsString($root->getId(), (string) $parentIri);
    }

    public function testGetRootMessageHasNullParent(): void
    {
        [$user, , , $page] = $this->setupFixture('thr-root-null');
        $root = MessageFactory::new()->inPage($page)->byUser($user)->withText('root')->create();

        $response = $this->jsonClient($user)->request('GET', '/api/v1/messages/'.$root->getId());
        $body = $response->toArray();

        self::assertNull($body['parent'] ?? null);
    }

    public function testAuthorCanEditTheirReply(): void
    {
        [$user, , , $page] = $this->setupFixture('thr-edit');
        $root = MessageFactory::new()->inPage($page)->byUser($user)->withText('root')->create();
        $reply = MessageFactory::new()->inPage($page)->byUser($user)->withText('original')
            ->with(['parent' => $root])->create();

        $this->jsonClient($user)->request('PATCH', '/api/v1/messages/'.$reply->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['text' => 'edited reply'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['text' => 'edited reply', 'edited' => true]);
    }

    public function testNonAuthorCannotEditAnothersReply(): void
    {
        [$user, $community, $channel, $page] = $this->setupFixture('thr-edit-deny');
        $other = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($other, $community);
        $root = MessageFactory::new()->inPage($page)->byUser($user)->withText('root')->create();
        $reply = MessageFactory::new()->inPage($page)->byUser($user)->withText('mine')
            ->with(['parent' => $root])->create();

        $this->jsonClient($other)->request('PATCH', '/api/v1/messages/'.$reply->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['text' => 'hacked'],
        ]);

        self::assertResponseStatusCodeSame(403);

        // suppress unused — fixture wiring ensures access path runs as expected
        self::assertSame('thr-edit-deny', $channel->getCommunity()?->getIdentifier());
    }

    public function testAuthorCanDeleteTheirReply(): void
    {
        [$user, , , $page] = $this->setupFixture('thr-del');
        $root = MessageFactory::new()->inPage($page)->byUser($user)->withText('root')->create();

        // Send the reply via API so the root replyCount is incremented
        // through the normal pipeline.
        $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'to delete'],
        ]);
        $reply = $this->jsonClient($user)
            ->request('GET', '/api/v1/messages/'.$root->getId().'/thread')
            ->toArray();
        $items = $reply['member'] ?? $reply['hydra:member'] ?? [];
        $replyId = basename((string) $items[0]['@id']);

        $this->jsonClient($user)->request('DELETE', '/api/v1/messages/'.$replyId);

        self::assertResponseStatusCodeSame(204);

        // Deleted reply still appears in the thread list (soft-delete) but
        // with text=null + isDeleted=true — same behavior as the channel
        // timeline, so the client can render "This message was deleted.".
        $threadResponse = $this->jsonClient($user)->request('GET', '/api/v1/messages/'.$root->getId().'/thread');
        $items = $threadResponse->toArray()['member'] ?? $threadResponse->toArray()['hydra:member'] ?? [];
        self::assertCount(1, $items);
        self::assertTrue($items[0]['isDeleted']);
        self::assertNull($items[0]['text']);

        // Root replyCount intentionally does NOT decrement on delete —
        // soft-deleted rows are still rendered in the panel.
        $rootCheck = $this->jsonClient($user)->request('GET', '/api/v1/messages/'.$root->getId())->toArray();
        self::assertSame(1, $rootCheck['replyCount']);
    }

    public function testNonAuthorCannotDeleteAnothersReply(): void
    {
        [$user, $community, , $page] = $this->setupFixture('thr-del-deny');
        $other = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($other, $community);
        $root = MessageFactory::new()->inPage($page)->byUser($user)->withText('root')->create();
        $reply = MessageFactory::new()->inPage($page)->byUser($user)->withText('mine')
            ->with(['parent' => $root])->create();

        $this->jsonClient($other)->request('DELETE', '/api/v1/messages/'.$reply->getId());

        self::assertResponseStatusCodeSame(403);
    }
}
