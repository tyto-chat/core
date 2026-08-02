<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityEmojiFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\ConversationFactory;
use App\Tests\Factory\ConversationMemberFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ReactionTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /** @return array{\App\Entity\Community, \App\Entity\Channel, \App\Entity\Message} */
    private function createMessageInCommunity(object $user): array
    {
        $community = CommunityFactory::new()->withIdentifier('react-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($user)->create();

        return [$community, $channel, $message];
    }

    public function testMemberCanAddReaction(): void
    {
        $user = UserFactory::createOne();
        [, , $message] = $this->createMessageInCommunity($user);

        $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$message->getId().'/reactions', [
            'json' => ['emoji' => '👍'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testAddingDuplicateReactionIsIdempotent(): void
    {
        $user = UserFactory::createOne();
        [, , $message] = $this->createMessageInCommunity($user);

        $client = $this->jsonClient($user);
        $client->request('POST', '/api/v1/messages/'.$message->getId().'/reactions', [
            'json' => ['emoji' => '❤️'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $client->request('POST', '/api/v1/messages/'.$message->getId().'/reactions', [
            'json' => ['emoji' => '❤️'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testAnonymousCannotAddReaction(): void
    {
        $user = UserFactory::createOne();
        [, , $message] = $this->createMessageInCommunity($user);

        $this->jsonClient()->request('POST', '/api/v1/messages/'.$message->getId().'/reactions', [
            'json' => ['emoji' => '👍'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAuthorCanDeleteReaction(): void
    {
        $user = UserFactory::createOne();
        [, , $message] = $this->createMessageInCommunity($user);

        $client = $this->jsonClient($user);
        $response = $client->request('POST', '/api/v1/messages/'.$message->getId().'/reactions', [
            'json' => ['emoji' => '👍'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $reactionIri = $response->toArray()['@id'];
        $client->request('DELETE', $reactionIri);

        self::assertResponseStatusCodeSame(204);
    }

    public function testNonAuthorCannotDeleteReaction(): void
    {
        $owner = UserFactory::createOne();
        $other = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('react-c2')->create();
        CommunityMemberFactory::createForUserAndCommunity($owner, $community);
        CommunityMemberFactory::createForUserAndCommunity($other, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ch2'])->create();
        ChannelMemberFactory::createForUserAndChannel($owner, $channel);
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($owner)->create();

        $ownerClient = $this->jsonClient($owner);
        $response = $ownerClient->request('POST', '/api/v1/messages/'.$message->getId().'/reactions', [
            'json' => ['emoji' => '👎'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $reactionIri = $response->toArray()['@id'];

        $this->jsonClient($other)->request('DELETE', $reactionIri);

        self::assertResponseStatusCodeSame(403);
    }

    public function testBlankEmojiReturns422(): void
    {
        $user = UserFactory::createOne();
        [, , $message] = $this->createMessageInCommunity($user);

        $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$message->getId().'/reactions', [
            'json' => ['emoji' => ''],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testNonChannelMemberCannotReactToMessageInPrivateChannel(): void
    {
        $owner = UserFactory::createOne();
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('react-priv')->create();
        CommunityMemberFactory::createForUserAndCommunity($owner, $community);
        CommunityMemberFactory::createForUserAndCommunity($outsider, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'priv-react'])->create();
        ChannelMemberFactory::createForUserAndChannel($owner, $channel);
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($owner)->create();

        $this->jsonClient($outsider)->request('POST', '/api/v1/messages/'.$message->getId().'/reactions', [
            'json' => ['emoji' => '👍'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanDeleteAnyReaction(): void
    {
        $owner = UserFactory::createOne();
        $admin = UserFactory::new()->admin()->create();
        [, , $message] = $this->createMessageInCommunity($owner);

        $response = $this->jsonClient($owner)->request('POST', '/api/v1/messages/'.$message->getId().'/reactions', [
            'json' => ['emoji' => '😂'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $reactionIri = $response->toArray()['@id'];

        $this->jsonClient($admin)->request('DELETE', $reactionIri);

        self::assertResponseStatusCodeSame(204);
    }

    public function testReactionWithUnicodeGlyphNotInCommunityListIsAccepted(): void
    {
        $user = UserFactory::createOne();
        [, , $message] = $this->createMessageInCommunity($user);

        $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$message->getId().'/reactions', [
            'json' => ['emoji' => '🦄'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        // Raw Unicode glyphs bypass the community list — anything typeable is a
        // valid reaction so users aren't trapped by a missing seed.
        self::assertResponseStatusCodeSame(201);
    }

    #[DataProvider('provideFlagEmojis')]
    public function testReactionWithFlagEmojiIsAccepted(string $flag): void
    {
        $user = UserFactory::createOne();
        [, , $message] = $this->createMessageInCommunity($user);

        $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$message->getId().'/reactions', [
            'json' => ['emoji' => $flag],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        self::assertResponseStatusCodeSame(201);
    }

    /** @return iterable<string, array{string}> */
    public static function provideFlagEmojis(): iterable
    {
        yield 'country flag (regional indicators)' => ['🇵🇱'];
        yield 'subdivision flag (tag sequence)' => ['🏴󠁧󠁢󠁳󠁣󠁴󠁿'];
        yield 'rainbow flag (ZWJ sequence)' => ['🏳️‍🌈'];
    }

    public function testReactionWithPlainTextReturns422(): void
    {
        $user = UserFactory::createOne();
        [, , $message] = $this->createMessageInCommunity($user);

        $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$message->getId().'/reactions', [
            'json' => ['emoji' => 'lol'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testReactionWithKnownShortcodeIsAccepted(): void
    {
        $user = UserFactory::createOne();
        [$community, , $message] = $this->createMessageInCommunity($user);
        CommunityEmojiFactory::new()->with([
            'community' => $community,
            'shortcode' => ':unicorn:',
            'position' => 100,
        ])->create();

        $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$message->getId().'/reactions', [
            'json' => ['emoji' => ':unicorn:'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testReactionWithUnknownShortcodeReturns422(): void
    {
        $user = UserFactory::createOne();
        [, , $message] = $this->createMessageInCommunity($user);

        $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$message->getId().'/reactions', [
            'json' => ['emoji' => ':noexist:'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /** @return array{\App\Entity\Conversation, \App\Entity\Message, \App\Entity\User} */
    private function createMessageInConversation(): array
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('react-dm-shared')->create();
        CommunityMemberFactory::createForUserAndCommunity($a, $community);
        CommunityMemberFactory::createForUserAndCommunity($b, $community);

        $conversation = ConversationFactory::new()->withParticipants([$a, $b])->create();
        ConversationMemberFactory::createForUserAndConversation($a, $conversation);
        ConversationMemberFactory::createForUserAndConversation($b, $conversation);

        $page = MessagePageFactory::new()->forConversation($conversation)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($a)->create();

        return [$conversation, $message, $b];
    }

    public function testActiveMemberCanReactWithUnicodeOnDirectMessage(): void
    {
        [, $message, $reactor] = $this->createMessageInConversation();

        $this->jsonClient($reactor)->request('POST', '/api/v1/messages/'.$message->getId().'/reactions', [
            'json' => ['emoji' => '🎉'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testShortcodeReactionOnDirectMessageReturns422(): void
    {
        [, $message, $reactor] = $this->createMessageInConversation();

        $this->jsonClient($reactor)->request('POST', '/api/v1/messages/'.$message->getId().'/reactions', [
            'json' => ['emoji' => ':unicorn:'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testNonMemberCannotReactOnDirectMessage(): void
    {
        [, $message] = $this->createMessageInConversation();
        $outsider = UserFactory::createOne();

        $this->jsonClient($outsider)->request('POST', '/api/v1/messages/'.$message->getId().'/reactions', [
            'json' => ['emoji' => '👍'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testMemberCanReactToThreadReply(): void
    {
        $user = UserFactory::createOne();
        [, , $root] = $this->createMessageInCommunity($user);

        // Create a reply via the API so it goes through the normal pipeline.
        $replyResponse = $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'a reply'],
        ]);
        self::assertResponseStatusCodeSame(201);
        $replyId = basename((string) $replyResponse->toArray()['@id']);

        $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$replyId.'/reactions', [
            'json' => ['emoji' => '👍'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testReplyReactionAppearsOnReplyNotRoot(): void
    {
        $user = UserFactory::createOne();
        [, , $root] = $this->createMessageInCommunity($user);

        $replyResponse = $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'reply target'],
        ]);
        $replyId = basename((string) $replyResponse->toArray()['@id']);

        $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$replyId.'/reactions', [
            'json' => ['emoji' => '🎉'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        self::assertResponseStatusCodeSame(201);

        // Reaction lives on the reply, never on the root.
        $rootCheck = $this->jsonClient($user)->request('GET', '/api/v1/messages/'.$root->getId())->toArray();
        self::assertNull($rootCheck['reactions'] ?? null);

        $replyCheck = $this->jsonClient($user)->request('GET', '/api/v1/messages/'.$replyId)->toArray();
        self::assertArrayHasKey('🎉', $replyCheck['reactions']);
    }

    public function testReplyReactionsAppearInThreadEndpoint(): void
    {
        $user = UserFactory::createOne();
        [, , $root] = $this->createMessageInCommunity($user);

        $replyResponse = $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'reply for thread list'],
        ]);
        $replyId = basename((string) $replyResponse->toArray()['@id']);

        $this->jsonClient($user)->request('POST', '/api/v1/messages/'.$replyId.'/reactions', [
            'json' => ['emoji' => '✨'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $threadResponse = $this->jsonClient($user)->request('GET', '/api/v1/messages/'.$root->getId().'/thread');
        $items = $threadResponse->toArray()['member'] ?? $threadResponse->toArray()['hydra:member'] ?? [];
        self::assertCount(1, $items);
        self::assertArrayHasKey('✨', $items[0]['reactions']);
    }
}
