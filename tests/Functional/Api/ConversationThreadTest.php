<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Repository\WebhookDeliveryRepository;
use App\Tests\Factory\ConversationFactory;
use App\Tests\Factory\ConversationMemberFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\WebhookFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ConversationThreadTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testParticipantCanReplyToDmMessage(): void
    {
        $alice = UserFactory::createOne();
        $bob = UserFactory::createOne();
        $conversation = ConversationFactory::new()->withParticipants([$alice, $bob])->create();
        ConversationMemberFactory::createForUserAndConversation($alice, $conversation);
        ConversationMemberFactory::createForUserAndConversation($bob, $conversation);
        $page = MessagePageFactory::new()->forConversation($conversation)->create();
        $root = MessageFactory::new()->inPage($page)->byUser($bob)->withText('root msg')->create();

        $response = $this->jsonClient($alice)->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'a dm reply'],
        ]);

        self::assertResponseStatusCodeSame(201);
        $body = $response->toArray();
        self::assertSame('a dm reply', $body['text']);
        self::assertSame($conversation->getIdentifier(), $body['conversationIdentifier']);

        $rootFresh = $this->jsonClient($alice)->request('GET', '/api/v1/messages/'.$root->getId())->toArray();
        self::assertSame(1, $rootFresh['replyCount']);
    }

    public function testNonParticipantCannotReplyToDmMessage(): void
    {
        $alice = UserFactory::createOne();
        $bob = UserFactory::createOne();
        $stranger = UserFactory::createOne();
        $conversation = ConversationFactory::new()->withParticipants([$alice, $bob])->create();
        ConversationMemberFactory::createForUserAndConversation($alice, $conversation);
        ConversationMemberFactory::createForUserAndConversation($bob, $conversation);
        $page = MessagePageFactory::new()->forConversation($conversation)->create();
        $root = MessageFactory::new()->inPage($page)->byUser($bob)->withText('root msg')->create();

        $this->jsonClient($stranger)->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'intrusion'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testCannotReplyToDmReply(): void
    {
        $alice = UserFactory::createOne();
        $bob = UserFactory::createOne();
        $conversation = ConversationFactory::new()->withParticipants([$alice, $bob])->create();
        ConversationMemberFactory::createForUserAndConversation($alice, $conversation);
        ConversationMemberFactory::createForUserAndConversation($bob, $conversation);
        $page = MessagePageFactory::new()->forConversation($conversation)->create();
        $root = MessageFactory::new()->inPage($page)->byUser($bob)->withText('root msg')->create();

        $reply = $this->jsonClient($alice)->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'first level'],
        ])->toArray();
        $replyId = basename((string) $reply['@id']);

        $this->jsonClient($bob)->request('POST', '/api/v1/messages/'.$replyId.'/replies', [
            'json' => ['text' => 'second level'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testDmReplyNotifiesOtherParticipant(): void
    {
        $alice = UserFactory::createOne();
        $bob = UserFactory::createOne();
        $conversation = ConversationFactory::new()->withParticipants([$alice, $bob])->create();
        ConversationMemberFactory::createForUserAndConversation($alice, $conversation);
        ConversationMemberFactory::createForUserAndConversation($bob, $conversation);
        $page = MessagePageFactory::new()->forConversation($conversation)->create();
        $root = MessageFactory::new()->inPage($page)->byUser($bob)->withText('root msg')->create();

        $this->jsonClient($alice)->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'ping'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $notifications = $this->jsonClient($bob)->request('GET', '/api/v1/me/notifications')->toArray();
        $items = $notifications['hydra:member'] ?? $notifications;
        $types = array_column($items, 'type');
        self::assertContains('dm_message', $types);
    }

    public function testDmReplyEmitsNoWebhook(): void
    {
        $alice = UserFactory::createOne();
        $bob = UserFactory::createOne();
        WebhookFactory::new()->withTrigger('message.replied')->with(['isActive' => true])->create();
        $conversation = ConversationFactory::new()->withParticipants([$alice, $bob])->create();
        ConversationMemberFactory::createForUserAndConversation($alice, $conversation);
        ConversationMemberFactory::createForUserAndConversation($bob, $conversation);
        $page = MessagePageFactory::new()->forConversation($conversation)->create();
        $root = MessageFactory::new()->inPage($page)->byUser($bob)->withText('root msg')->create();

        $this->jsonClient($alice)->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'private'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $deliveries = static::getContainer()->get(WebhookDeliveryRepository::class)->findAll();
        self::assertSame([], $deliveries);
    }

    public function testParticipantCanReadDmThreadAndStrangerCannot(): void
    {
        $alice = UserFactory::createOne();
        $bob = UserFactory::createOne();
        $stranger = UserFactory::createOne();
        $conversation = ConversationFactory::new()->withParticipants([$alice, $bob])->create();
        ConversationMemberFactory::createForUserAndConversation($alice, $conversation);
        ConversationMemberFactory::createForUserAndConversation($bob, $conversation);
        $page = MessagePageFactory::new()->forConversation($conversation)->create();
        $root = MessageFactory::new()->inPage($page)->byUser($bob)->withText('root msg')->create();
        $this->jsonClient($alice)->request('POST', '/api/v1/messages/'.$root->getId().'/replies', [
            'json' => ['text' => 'reply one'],
        ]);

        $replies = $this->jsonClient($bob)->request('GET', '/api/v1/messages/'.$root->getId().'/thread')->toArray();
        $items = $replies['member'] ?? $replies['hydra:member'] ?? [];
        self::assertCount(1, $items);

        $this->jsonClient($stranger)->request('GET', '/api/v1/messages/'.$root->getId().'/thread');
        self::assertResponseStatusCodeSame(403);

        $this->jsonClient()->request('GET', '/api/v1/messages/'.$root->getId().'/thread');
        self::assertResponseStatusCodeSame(401);
    }
}
