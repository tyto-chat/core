<?php

declare(strict_types=1);

namespace App\Tests\Functional\Realtime;

use App\Entity\Message;
use App\Entity\Notification;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ConversationFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\NotificationFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class EntityTopicsTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testChannelRootPublishesToChannelTopic(): void
    {
        $channel = ChannelFactory::createOne();
        $page = MessagePageFactory::createOne(['channel' => $channel]);
        $root = MessageFactory::createOne(['page' => $page]);

        self::assertSame('c/'.$channel->getId(), $root->getContainerTopic());
        self::assertSame('c/'.$channel->getId(), $root->getPublishTopic());
        self::assertSame('', $root->getThreadTopic());
    }

    public function testChannelReplyPublishesToThreadTopic(): void
    {
        $channel = ChannelFactory::createOne();
        $page = MessagePageFactory::createOne(['channel' => $channel]);
        $root = MessageFactory::createOne(['page' => $page]);
        $reply = MessageFactory::createOne(['page' => $page, 'parent' => $root]);

        self::assertSame('c/'.$channel->getId().'/t', $reply->getThreadTopic());
        self::assertSame('c/'.$channel->getId().'/t', $reply->getPublishTopic());
    }

    public function testConversationRootAndReplyTopics(): void
    {
        $conversation = ConversationFactory::createOne();
        $page = MessagePageFactory::new()->forConversation($conversation)->create();
        $root = MessageFactory::createOne(['page' => $page]);
        $reply = MessageFactory::createOne(['page' => $page, 'parent' => $root]);

        self::assertSame('m/'.$conversation->getId(), $root->getPublishTopic());
        self::assertSame('m/'.$conversation->getId().'/t', $reply->getPublishTopic());
    }

    public function testConversationTopic(): void
    {
        $conversation = ConversationFactory::createOne();

        self::assertSame('m/'.$conversation->getId(), $conversation->getTopic());
    }

    public function testNotificationRecipientTopic(): void
    {
        $recipient = UserFactory::createOne();
        $notification = NotificationFactory::createOne(['recipient' => $recipient]);

        self::assertSame('u/'.$recipient->getId().'/n', $notification->getRecipientNotificationTopic());
    }

    public function testUnsavedObjectsProduceEmptyTopics(): void
    {
        $message = new Message();

        self::assertSame('', $message->getContainerTopic());
        self::assertSame('', $message->getThreadTopic());
        self::assertSame('', $message->getPublishTopic());
        self::assertSame('', (new Notification())->getRecipientNotificationTopic());
    }
}
