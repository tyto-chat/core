<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\ConversationFactory;
use App\Tests\Factory\ConversationMemberFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class MessagePermalinkDmTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testParticipantGetsDmMessageWithConversationIdentifier(): void
    {
        $alice = UserFactory::createOne();
        $bob = UserFactory::createOne();
        $conversation = ConversationFactory::new()->withParticipants([$alice, $bob])->create();
        ConversationMemberFactory::createForUserAndConversation($alice, $conversation);
        ConversationMemberFactory::createForUserAndConversation($bob, $conversation);
        $page = MessagePageFactory::new()->forConversation($conversation)->create();
        $msg = MessageFactory::new()->inPage($page)->byUser($bob)->withText('dm needle')->create();

        $response = $this->jsonClient($alice)->request('GET', '/api/v1/messages/'.$msg->getId());

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertSame($conversation->getIdentifier(), $body['conversationIdentifier']);
        self::assertNull($body['channelIdentifier'] ?? null);
        self::assertNull($body['communityIdentifier'] ?? null);
        self::assertSame($page->getPageNumber(), $body['pageNumber']);
    }

    public function testNonParticipantCannotFetchDmMessage(): void
    {
        $alice = UserFactory::createOne();
        $bob = UserFactory::createOne();
        $stranger = UserFactory::createOne();
        $conversation = ConversationFactory::new()->withParticipants([$alice, $bob])->create();
        ConversationMemberFactory::createForUserAndConversation($alice, $conversation);
        ConversationMemberFactory::createForUserAndConversation($bob, $conversation);
        $page = MessagePageFactory::new()->forConversation($conversation)->create();
        $msg = MessageFactory::new()->inPage($page)->byUser($bob)->withText('secret')->create();

        $this->jsonClient($stranger)->request('GET', '/api/v1/messages/'.$msg->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminWithoutMembershipCannotFetchDmMessage(): void
    {
        $alice = UserFactory::createOne();
        $bob = UserFactory::createOne();
        $admin = UserFactory::new()->admin()->create();
        $conversation = ConversationFactory::new()->withParticipants([$alice, $bob])->create();
        ConversationMemberFactory::createForUserAndConversation($alice, $conversation);
        ConversationMemberFactory::createForUserAndConversation($bob, $conversation);
        $page = MessagePageFactory::new()->forConversation($conversation)->create();
        $msg = MessageFactory::new()->inPage($page)->byUser($bob)->withText('secret')->create();

        $this->jsonClient($admin)->request('GET', '/api/v1/messages/'.$msg->getId());

        self::assertResponseStatusCodeSame(403);
    }
}
