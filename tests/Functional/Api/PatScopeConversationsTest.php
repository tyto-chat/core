<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Conversation;
use App\Entity\User;
use App\Enum\ApiKey\ApiKeyScope;
use App\Tests\Factory\ApiKeyFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\ConversationFactory;
use App\Tests\Factory\ConversationMemberFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class PatScopeConversationsTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /** @return array{User, User} two users sharing a community */
    private function createSharedCommunityUsers(string $communityId): array
    {
        $user = UserFactory::createOne();
        $other = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier($communityId)->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        CommunityMemberFactory::createForUserAndCommunity($other, $community);

        return [$user, $other];
    }

    /** @return array{User, User, Conversation} two participants plus their conversation, with a message page */
    private function setupConversationWithMembers(string $communityId): array
    {
        [$user, $other] = $this->createSharedCommunityUsers($communityId);
        $conversation = ConversationFactory::new()->withParticipants([$user, $other])->create();
        ConversationMemberFactory::createForUserAndConversation($user, $conversation);
        ConversationMemberFactory::createForUserAndConversation($other, $conversation);
        MessagePageFactory::new()->forConversation($conversation)->create();

        return [$user, $other, $conversation];
    }

    public function testConversationsWriteCanCreateConversation(): void
    {
        [$user, $other] = $this->createSharedCommunityUsers('pat-conv-create');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ConversationsWrite->value]]);

        $this->withToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/conversations',
            ['json' => ['memberUserIds' => [$other->getId()]]],
        );

        self::assertResponseStatusCodeSame(201);
    }

    public function testConversationsReadCannotCreateConversation(): void
    {
        [$user, $other] = $this->createSharedCommunityUsers('pat-conv-create-ro');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ConversationsRead->value]]);

        $response = $this->withToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/conversations',
            ['json' => ['memberUserIds' => [$other->getId()]]],
        );

        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString('conversations:write', $headers['www-authenticate'][0] ?? '');
    }

    public function testWrongScopeRejectedForCreateConversation(): void
    {
        [$user, $other] = $this->createSharedCommunityUsers('pat-conv-create-wrong');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ProfileWrite->value]]);

        $response = $this->withToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/conversations',
            ['json' => ['memberUserIds' => [$other->getId()]]],
        );

        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString('conversations:write', $headers['www-authenticate'][0] ?? '');
    }

    public function testConversationsWriteCanSendDirectMessage(): void
    {
        [$user, , $conversation] = $this->setupConversationWithMembers('pat-conv-send');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ConversationsWrite->value]]);

        $this->withToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/messages',
            ['json' => ['text' => 'hello from a bot']],
        );

        self::assertResponseStatusCodeSame(201);
    }

    public function testConversationsReadCannotSendDirectMessage(): void
    {
        [$user, , $conversation] = $this->setupConversationWithMembers('pat-conv-send-ro');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ConversationsRead->value]]);

        $response = $this->withToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/messages',
            ['json' => ['text' => 'should not land']],
        );

        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString('conversations:write', $headers['www-authenticate'][0] ?? '');
    }

    public function testConversationsWriteCanMuteConversation(): void
    {
        [$user, , $conversation] = $this->setupConversationWithMembers('pat-conv-mute');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ConversationsWrite->value]]);

        $this->withToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/mute',
            ['json' => ['mutedUntil' => null], 'headers' => ['Content-Type' => 'application/ld+json']],
        );

        self::assertResponseIsSuccessful();
    }

    public function testConversationsWriteCanMarkRead(): void
    {
        [$user, , $conversation] = $this->setupConversationWithMembers('pat-conv-mark-read');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ConversationsWrite->value]]);

        $this->withToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/mark-read',
        );

        self::assertResponseIsSuccessful();
    }

    public function testConversationsWriteCanSendTypingPing(): void
    {
        [$user, , $conversation] = $this->setupConversationWithMembers('pat-conv-typing');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ConversationsWrite->value]]);

        $this->withToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/typing',
        );

        self::assertResponseStatusCodeSame(204);
    }

    private function minimalPng(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
    }

    private function createUploadedFile(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pat_conv_att_test_');
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

    public function testConversationsWriteCanUploadAttachment(): void
    {
        [$user, , $conversation] = $this->setupConversationWithMembers('pat-conv-attach');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ConversationsWrite->value]]);

        $this->uploadClientWithToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/attachments',
            ['extra' => ['files' => ['file' => $this->createUploadedFile()]]],
        );

        self::assertResponseStatusCodeSame(201);
    }

    public function testConversationsReadCanListConversations(): void
    {
        [$user] = $this->setupConversationWithMembers('pat-conv-list');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ConversationsRead->value]]);

        $this->withToken($issued['plainToken'])->request('GET', '/api/v1/conversations');

        self::assertResponseStatusCodeSame(200);
    }

    public function testWrongScopeRejectedForListConversations(): void
    {
        [$user] = $this->setupConversationWithMembers('pat-conv-list-wrong');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ProfileRead->value]]);

        $response = $this->withToken($issued['plainToken'])->request('GET', '/api/v1/conversations');

        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString('conversations:read', $headers['www-authenticate'][0] ?? '');
    }

    public function testConversationsReadCanGetConversation(): void
    {
        [$user, , $conversation] = $this->setupConversationWithMembers('pat-conv-get');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ConversationsRead->value]]);

        $this->withToken($issued['plainToken'])->request(
            'GET',
            '/api/v1/conversations/'.$conversation->getIdentifier(),
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testConversationsReadCanGetCurrentMessages(): void
    {
        [$user, , $conversation] = $this->setupConversationWithMembers('pat-conv-current');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ConversationsRead->value]]);

        $this->withToken($issued['plainToken'])->request(
            'GET',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/messages/current',
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testConversationsReadCanListPages(): void
    {
        [$user, , $conversation] = $this->setupConversationWithMembers('pat-conv-pages');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ConversationsRead->value]]);

        $this->withToken($issued['plainToken'])->request(
            'GET',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/pages',
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testConversationsReadCanGetPageByNumber(): void
    {
        [$user, , $conversation] = $this->setupConversationWithMembers('pat-conv-page-n');
        $page = $conversation->getPages()->first();
        \assert(false !== $page);
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ConversationsRead->value]]);

        $this->withToken($issued['plainToken'])->request(
            'GET',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/pages/'.$page->getPageNumber(),
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testConversationsReadCanSearchConversation(): void
    {
        [$user, , $conversation] = $this->setupConversationWithMembers('pat-conv-search');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::ConversationsRead->value]]);

        $this->withToken($issued['plainToken'])->request(
            'GET',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/search?q=hello',
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testMessagesScopeCannotListConversations(): void
    {
        [$user] = $this->setupConversationWithMembers('pat-conv-domain-list');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesRead->value, ApiKeyScope::MessagesWrite->value]]);

        $response = $this->withToken($issued['plainToken'])->request('GET', '/api/v1/conversations');

        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString('conversations:read', $headers['www-authenticate'][0] ?? '');
    }

    public function testMessagesScopeCannotSendDirectMessage(): void
    {
        [$user, , $conversation] = $this->setupConversationWithMembers('pat-conv-domain-send');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesRead->value, ApiKeyScope::MessagesWrite->value]]);

        $response = $this->withToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/messages',
            ['json' => ['text' => 'a channel bot should not be able to send this']],
        );

        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString('conversations:write', $headers['www-authenticate'][0] ?? '');
    }

    public function testMessagesScopeCannotUploadAttachment(): void
    {
        [$user, , $conversation] = $this->setupConversationWithMembers('pat-conv-domain-attach');
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => [ApiKeyScope::MessagesRead->value, ApiKeyScope::MessagesWrite->value]]);

        $response = $this->uploadClientWithToken($issued['plainToken'])->request(
            'POST',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/attachments',
            ['extra' => ['files' => ['file' => $this->createUploadedFile()]]],
        );

        self::assertResponseStatusCodeSame(403);
        $headers = $response->getHeaders(false);
        self::assertStringContainsString('insufficient_scope', $headers['www-authenticate'][0] ?? '');
        self::assertStringContainsString('conversations:write', $headers['www-authenticate'][0] ?? '');
    }

    private function withToken(string $token): Client
    {
        return static::createClient(defaultOptions: [
            'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/ld+json'],
        ]);
    }
}
