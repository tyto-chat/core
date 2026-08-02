<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Notification;
use App\Enum\Notification\NotificationType;
use App\Service\Notification\NotificationServiceInterface;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class NotificationCoalescingTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testActivityCoalescesIntoOneKeyedOpenRow(): void
    {
        $recipient = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('coalesce-c')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'coalesce-ch'])->create();

        $service = static::getContainer()->get(NotificationServiceInterface::class);
        $service->upsertChannelActivity($recipient, $channel, 11, 'Alice', '/api/v1/messages/a');
        $service->upsertChannelActivity($recipient, $channel, 12, 'Bob', '/api/v1/messages/b');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $rows = $em->getRepository(Notification::class)->findBy(['type' => NotificationType::ChannelActivity]);

        self::assertCount(1, $rows);
        self::assertSame(2, $rows[0]->getMessageCount());
        self::assertSame(
            sprintf('%d:coalesce-c:coalesce-ch', $recipient->getId()),
            $rows[0]->getCoalesceKey(),
        );
    }

    public function testMarkingReadClearsKeySoNextActivityOpensFreshRow(): void
    {
        $recipient = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('coalesce-c2')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'coalesce-ch2'])->create();

        $service = static::getContainer()->get(NotificationServiceInterface::class);
        $first = $service->upsertChannelActivity($recipient, $channel, 11, 'Alice', '/api/v1/messages/a');

        $first->setIsRead(true);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->flush();

        self::assertNull($first->getCoalesceKey());

        $second = $service->upsertChannelActivity($recipient, $channel, 12, 'Bob', '/api/v1/messages/b');

        self::assertNotSame($first->getId(), $second->getId());
        self::assertNotNull($second->getCoalesceKey());
        self::assertCount(2, $em->getRepository(Notification::class)->findBy(['type' => NotificationType::ChannelActivity]));
    }
}
