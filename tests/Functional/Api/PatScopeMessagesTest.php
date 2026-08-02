<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\MessagePage;
use App\Entity\User;
use App\Enum\ApiKey\ApiKeyScope;
use App\Enum\Channel\ChannelRole;
use App\Tests\Factory\ApiKeyFactory;
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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class PatScopeMessagesTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /** @return array{User, Community, Channel, MessagePage} */
    private function setupChannelWithMember(string $communityId, string $channelId): array
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier($communityId)->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => $channelId])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);
        $page = MessagePageFactory::new()->forChannel($channel)->create();

        return [$user, $community, $channel, $page];
    }

    /** @return array{User, Conversation, Message} the author, the DM, and a root message they sent in it */
    private function setupDmMessage(string $communityId): array
    {
        $user = UserFactory::createOne();
        $other = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier($communityId)->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        CommunityMemberFactory::createForUserAndCommunity($other, $community);
        $conversation = ConversationFactory::new()->withParticipants([$user, $other])->create();
        ConversationMemberFactory::createForUserAndConversation($user, $conversation);
        ConversationMemberFactory::createForUserAndConversation($other, $conversation);
        $page = MessagePageFactory::new()->forConversation($conversation)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($user)->create();

        return [$user, $conversation, $message];
    }

    private function assertInsufficientScope(mixed $response, string $scope): void
    {
        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString($scope, $headers['www-authenticate'][0] ?? '');
    }

    public function testMessagesWriteCanSendChannelMessage(): void
    {
        [$user] = $this->setupChannelWithMember('pat-msg-send', 'general');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesWrite->value]]);

        $this->withToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/communities/pat-msg-send/channels/general/messages',
            ['json' => ['text' => 'hello from a bot']],
        );

        self::assertResponseStatusCodeSame(201);
    }

    public function testMessagesReadCannotSendChannelMessage(): void
    {
        [$user] = $this->setupChannelWithMember('pat-msg-send-ro', 'general');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesRead->value]]);

        $response = $this->withToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/communities/pat-msg-send-ro/channels/general/messages',
            ['json' => ['text' => 'should not land']],
        );

        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString('messages:write', $headers['www-authenticate'][0] ?? '');
    }

    public function testWrongScopeRejectedForSendChannelMessage(): void
    {
        [$user] = $this->setupChannelWithMember('pat-msg-send-wrong', 'general');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ProfileWrite->value]]);

        $response = $this->withToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/communities/pat-msg-send-wrong/channels/general/messages',
            ['json' => ['text' => 'should not land']],
        );

        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString('messages:write', $headers['www-authenticate'][0] ?? '');
    }

    public function testMessagesWriteCanPostReply(): void
    {
        [$user, , , $page] = $this->setupChannelWithMember('pat-msg-reply', 'general');
        $root = MessageFactory::new()->inPage($page)->byUser($user)->create();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesWrite->value]]);

        $this->withToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/messages/'.$root->getId().'/replies',
            ['json' => ['text' => 'a reply']],
        );

        self::assertResponseStatusCodeSame(201);
    }

    public function testMessagesWriteCanAddReaction(): void
    {
        [$user, , , $page] = $this->setupChannelWithMember('pat-msg-react', 'general');
        $message = MessageFactory::new()->inPage($page)->byUser($user)->create();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesWrite->value]]);

        $this->withToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/messages/'.$message->getId().'/reactions',
            ['json' => ['emoji' => '👍'], 'headers' => ['Content-Type' => 'application/ld+json']],
        );

        self::assertResponseStatusCodeSame(201);
    }

    public function testMessagesWriteCanDeleteReaction(): void
    {
        [$user, , , $page] = $this->setupChannelWithMember('pat-msg-react-del', 'general');
        $message = MessageFactory::new()->inPage($page)->byUser($user)->create();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesWrite->value]]);

        $client = $this->withToken($issued['plainToken']);
        $response = $client->request(
            'POST',
            '/api/v1/messages/'.$message->getId().'/reactions',
            ['json' => ['emoji' => '👍'], 'headers' => ['Content-Type' => 'application/ld+json']],
        );
        $reactionIri = $response->toArray()['@id'];

        $client->request('DELETE', $reactionIri);

        self::assertResponseStatusCodeSame(204);
    }

    public function testMessagesWriteCanPinMessage(): void
    {
        [$author, $community, $channel, $page] = $this->setupChannelWithMember('pat-msg-pin', 'general');
        $mod = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($mod, $community);
        ChannelMemberFactory::createForUserAndChannel($mod, $channel, ChannelRole::Moderator);
        $message = MessageFactory::new()->inPage($page)->byUser($author)->create();
        $issued = ApiKeyFactory::createWithToken($mod, ['scopes' => [ApiKeyScope::MessagesWrite->value]]);

        $this->withToken($issued['plainToken'])->request('POST', '/api/v1/messages/'.$message->getId().'/pin');

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['pinned' => true]);
    }

    public function testMessagesWriteCanUnpinMessage(): void
    {
        [$author, $community, $channel, $page] = $this->setupChannelWithMember('pat-msg-unpin', 'general');
        $mod = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($mod, $community);
        ChannelMemberFactory::createForUserAndChannel($mod, $channel, ChannelRole::Moderator);
        $message = MessageFactory::new()->inPage($page)->byUser($author)
            ->with(['pinnedAt' => new \DateTimeImmutable(), 'pinnedBy' => $author])->create();
        $issued = ApiKeyFactory::createWithToken($mod, ['scopes' => [ApiKeyScope::MessagesWrite->value]]);

        $this->withToken($issued['plainToken'])->request('DELETE', '/api/v1/messages/'.$message->getId().'/pin');

        self::assertResponseStatusCodeSame(204);
    }

    public function testMessagesWriteCanEditMessage(): void
    {
        [$user, , , $page] = $this->setupChannelWithMember('pat-msg-edit', 'general');
        $message = MessageFactory::new()->inPage($page)->byUser($user)->create();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesWrite->value]]);

        $this->withToken($issued['plainToken'])->request('PATCH', '/api/v1/messages/'.$message->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['text' => 'edited via PAT'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['text' => 'edited via PAT']);
    }

    public function testMessagesWriteCanDeleteMessage(): void
    {
        [$user, , , $page] = $this->setupChannelWithMember('pat-msg-delete', 'general');
        $message = MessageFactory::new()->inPage($page)->byUser($user)->create();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesWrite->value]]);

        $this->withToken($issued['plainToken'])->request('DELETE', '/api/v1/messages/'.$message->getId());

        self::assertResponseStatusCodeSame(204);
    }

    public function testMessagesWriteCanSendTypingPing(): void
    {
        [$user] = $this->setupChannelWithMember('pat-msg-typing', 'general');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesWrite->value]]);

        $this->withToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/communities/pat-msg-typing/channels/general/typing',
        );

        self::assertResponseStatusCodeSame(204);
    }

    private function minimalPng(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
    }

    private function createUploadedFile(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pat_att_test_');
        file_put_contents((string) $tmp, $this->minimalPng());

        return new UploadedFile((string) $tmp, 'test.png', 'image/png', null, true);
    }

    private function uploadClientWithToken(string $token): Client
    {
        return static::createClient(defaultOptions: [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/ld+json',
                'Content-Type' => 'multipart/form-data',
            ],
        ]);
    }

    public function testMessagesWriteCanUploadChannelAttachment(): void
    {
        [$user] = $this->setupChannelWithMember('pat-msg-attach', 'general');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesWrite->value]]);

        $this->uploadClientWithToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/communities/pat-msg-attach/channels/general/attachments',
            ['extra' => ['files' => ['file' => $this->createUploadedFile()]]],
        );

        self::assertResponseStatusCodeSame(201);
    }

    public function testMessagesWriteCanDeleteAttachment(): void
    {
        [$user] = $this->setupChannelWithMember('pat-msg-attach-del', 'general');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesWrite->value]]);
        $client = $this->uploadClientWithToken($issued['plainToken']);

        $uploadResponse = $client->request(
            'POST',
            '/api/v1/communities/pat-msg-attach-del/channels/general/attachments',
            ['extra' => ['files' => ['file' => $this->createUploadedFile()]]],
        );
        $numericId = basename((string) $uploadResponse->toArray()['@id']);

        $client->request('DELETE', '/api/v1/attachments/'.$numericId);

        self::assertResponseStatusCodeSame(204);
    }

    public function testMessagesReadCanGetMessageById(): void
    {
        [$user, , , $page] = $this->setupChannelWithMember('pat-msg-get', 'general');
        $message = MessageFactory::new()->inPage($page)->byUser($user)->create();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesRead->value]]);

        $this->withToken($issued['plainToken'])->request('GET', '/api/v1/messages/'.$message->getId());

        self::assertResponseStatusCodeSame(200);
    }

    public function testWrongScopeRejectedForGetMessage(): void
    {
        [$user, , , $page] = $this->setupChannelWithMember('pat-msg-get-wrong', 'general');
        $message = MessageFactory::new()->inPage($page)->byUser($user)->create();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ProfileRead->value]]);

        $response = $this->withToken($issued['plainToken'])->request('GET', '/api/v1/messages/'.$message->getId());

        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString('messages:read', $headers['www-authenticate'][0] ?? '');
    }

    public function testMessagesReadCanListThread(): void
    {
        [$user, , , $page] = $this->setupChannelWithMember('pat-msg-thread', 'general');
        $root = MessageFactory::new()->inPage($page)->byUser($user)->create();
        MessageFactory::new()->inPage($page)->byUser($user)->with(['parent' => $root])->create();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesRead->value]]);

        $this->withToken($issued['plainToken'])->request('GET', '/api/v1/messages/'.$root->getId().'/thread');

        self::assertResponseStatusCodeSame(200);
    }

    public function testMessagesReadCanListMessageHistory(): void
    {
        [$author, $community, $channel, $page] = $this->setupChannelWithMember('pat-msg-history', 'general');
        $mod = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($mod, $community);
        ChannelMemberFactory::createForUserAndChannel($mod, $channel, ChannelRole::Moderator);
        $message = MessageFactory::new()->inPage($page)->byUser($author)->create();
        $issued = ApiKeyFactory::createWithToken($mod, ['scopes' => [ApiKeyScope::MessagesRead->value]]);

        $this->withToken($issued['plainToken'])->request('GET', '/api/v1/messages/'.$message->getId().'/history');

        self::assertResponseStatusCodeSame(200);
    }

    public function testMessagesReadCanGetCurrentChannelPage(): void
    {
        [$user] = $this->setupChannelWithMember('pat-msg-current', 'general');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesRead->value]]);

        $this->withToken($issued['plainToken'])->request(
            'GET',
            '/api/v1/communities/pat-msg-current/channels/general/messages/current',
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testMessagesReadCanListPinnedMessages(): void
    {
        [$user] = $this->setupChannelWithMember('pat-msg-pinlist', 'general');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesRead->value]]);

        $this->withToken($issued['plainToken'])->request(
            'GET',
            '/api/v1/communities/pat-msg-pinlist/channels/general/pinned-messages',
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testMessagesReadCanListChannelPages(): void
    {
        [$user] = $this->setupChannelWithMember('pat-msg-pages', 'general');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesRead->value]]);

        $this->withToken($issued['plainToken'])->request(
            'GET',
            '/api/v1/communities/pat-msg-pages/channels/general/pages',
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testMessagesReadCanGetChannelPageByNumber(): void
    {
        [$user, , , $page] = $this->setupChannelWithMember('pat-msg-page-n', 'general');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesRead->value]]);

        $this->withToken($issued['plainToken'])->request(
            'GET',
            '/api/v1/communities/pat-msg-page-n/channels/general/pages/'.$page->getPageNumber(),
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testMessagesReadCanSearchChannel(): void
    {
        [$user] = $this->setupChannelWithMember('pat-msg-search-ch', 'general');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesRead->value]]);

        $this->withToken($issued['plainToken'])->request(
            'GET',
            '/api/v1/communities/pat-msg-search-ch/channels/general/search?q=hello',
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testMessagesReadCanSearchCommunity(): void
    {
        [$user] = $this->setupChannelWithMember('pat-msg-search-c', 'general');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesRead->value]]);

        $this->withToken($issued['plainToken'])->request(
            'GET',
            '/api/v1/communities/pat-msg-search-c/search?q=hello',
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testMessagesScopeAloneCannotGetDmMessage(): void
    {
        [$user, , $message] = $this->setupDmMessage('pat-msg-dm-get');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesRead->value, ApiKeyScope::MessagesWrite->value]]);

        $response = $this->withToken($issued['plainToken'])->request('GET', '/api/v1/messages/'.$message->getId());

        $this->assertInsufficientScope($response, 'conversations:read');
    }

    public function testMessagesScopeAloneCannotListDmThread(): void
    {
        [$user, , $root] = $this->setupDmMessage('pat-msg-dm-thread');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesRead->value, ApiKeyScope::MessagesWrite->value]]);

        $response = $this->withToken($issued['plainToken'])->request('GET', '/api/v1/messages/'.$root->getId().'/thread');

        $this->assertInsufficientScope($response, 'conversations:read');
    }

    public function testMessagesScopeAloneCannotEditDmMessage(): void
    {
        [$user, , $message] = $this->setupDmMessage('pat-msg-dm-edit');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesRead->value, ApiKeyScope::MessagesWrite->value]]);

        $response = $this->withToken($issued['plainToken'])->request('PATCH', '/api/v1/messages/'.$message->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['text' => 'edited via PAT'],
        ]);

        $this->assertInsufficientScope($response, 'conversations:write');
    }

    public function testMessagesScopeAloneCannotDeleteDmMessage(): void
    {
        [$user, , $message] = $this->setupDmMessage('pat-msg-dm-delete');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesRead->value, ApiKeyScope::MessagesWrite->value]]);

        $response = $this->withToken($issued['plainToken'])->request('DELETE', '/api/v1/messages/'.$message->getId());

        $this->assertInsufficientScope($response, 'conversations:write');
    }

    public function testMessagesScopeAloneCannotReplyToDmMessage(): void
    {
        [$user, , $root] = $this->setupDmMessage('pat-msg-dm-reply');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesRead->value, ApiKeyScope::MessagesWrite->value]]);

        $response = $this->withToken($issued['plainToken'])->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'should not land'],
        ]);

        $this->assertInsufficientScope($response, 'conversations:write');
    }

    public function testMessagesScopeAloneStillWorksForChannelMessage(): void
    {
        [$user, , , $page] = $this->setupChannelWithMember('pat-msg-channel-still-works', 'general');
        $message = MessageFactory::new()->inPage($page)->byUser($user)->create();
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesRead->value, ApiKeyScope::MessagesWrite->value]]);

        $this->withToken($issued['plainToken'])->request('GET', '/api/v1/messages/'.$message->getId());
        self::assertResponseStatusCodeSame(200);

        $this->withToken($issued['plainToken'])->request('GET', '/api/v1/messages/'.$message->getId().'/thread');
        self::assertResponseStatusCodeSame(200);

        $this->withToken($issued['plainToken'])->request('PATCH', '/api/v1/messages/'.$message->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['text' => 'edited via PAT'],
        ]);
        self::assertResponseIsSuccessful();

        $this->withToken($issued['plainToken'])->request('POST', '/api/v1/messages/'.$message->getId().'/replies', ['json' => ['text' => 'a reply']]);
        self::assertResponseStatusCodeSame(201);

        $this->withToken($issued['plainToken'])->request('DELETE', '/api/v1/messages/'.$message->getId());
        self::assertResponseStatusCodeSame(204);
    }

    public function testCombinedMessagesAndConversationsScopeWorksOnDmMessage(): void
    {
        [$user, , $message] = $this->setupDmMessage('pat-msg-dm-combined');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [
            ApiKeyScope::MessagesRead->value,
            ApiKeyScope::MessagesWrite->value,
            ApiKeyScope::ConversationsRead->value,
            ApiKeyScope::ConversationsWrite->value,
        ]]);

        $this->withToken($issued['plainToken'])->request('GET', '/api/v1/messages/'.$message->getId());
        self::assertResponseStatusCodeSame(200);

        $this->withToken($issued['plainToken'])->request('GET', '/api/v1/messages/'.$message->getId().'/thread');
        self::assertResponseStatusCodeSame(200);

        $this->withToken($issued['plainToken'])->request('PATCH', '/api/v1/messages/'.$message->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['text' => 'edited via PAT'],
        ]);
        self::assertResponseIsSuccessful();

        $this->withToken($issued['plainToken'])->request('POST', '/api/v1/messages/'.$message->getId().'/replies', ['json' => ['text' => 'a reply']]);
        self::assertResponseStatusCodeSame(201);

        $this->withToken($issued['plainToken'])->request('DELETE', '/api/v1/messages/'.$message->getId());
        self::assertResponseStatusCodeSame(204);
    }

    public function testMessagesReadAloneCannotListDmReactionUsers(): void
    {
        [$user, , $message] = $this->setupDmMessage('pat-msg-dm-reactions');
        $fullIssued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesWrite->value, ApiKeyScope::ConversationsWrite->value]]);
        $this->withToken($fullIssued['plainToken'])->request('POST', '/api/v1/messages/'.$message->getId().'/reactions', [
            'json' => ['emoji' => '👍'], 'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $readOnlyIssued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesRead->value]]);
        $response = $this->withToken($readOnlyIssued['plainToken'])->request(
            'GET',
            '/api/v1/messages/'.$message->getId().'/reactions/'.rawurlencode('👍'),
        );

        $this->assertInsufficientScope($response, 'conversations:read');
    }

    public function testMessagesWriteAloneCannotDeleteDmAttachment(): void
    {
        [$user, $conversation] = $this->setupDmMessage('pat-msg-dm-attach-del');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesWrite->value, ApiKeyScope::ConversationsWrite->value]]);
        $uploadResponse = $this->uploadClientWithToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/attachments',
            ['extra' => ['files' => ['file' => $this->createUploadedFile()]]],
        );
        self::assertResponseStatusCodeSame(201);
        $attachmentIri = (string) $uploadResponse->toArray()['@id'];
        $numericId = basename($attachmentIri);

        $this->withToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/messages',
            ['json' => ['text' => '', 'attachmentIris' => [$attachmentIri]]],
        );
        self::assertResponseStatusCodeSame(201);

        $messagesOnlyIssued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesWrite->value]]);
        $response = $this->withToken($messagesOnlyIssued['plainToken'])
            ->request('DELETE', '/api/v1/attachments/'.$numericId);

        $this->assertInsufficientScope($response, 'conversations:write');
    }

    private function withToken(string $token): Client
    {
        return static::createClient(defaultOptions: [
            'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/ld+json'],
        ]);
    }
}
