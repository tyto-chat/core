<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\Channel\ChannelRole;
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
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class MessageHistoryTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testAdminCanGetMessageHistory(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('hist-c')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'hist-ch'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->create();

        $response = $this->jsonClient($admin)->request('GET', '/api/v1/messages/'.$message->getId().'/history');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertArrayHasKey('hydra:member', $data);
        self::assertCount(1, $data['hydra:member']);
    }

    public function testRegularUserCannotGetMessageHistory(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('hist-c2')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'hist-ch2'])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->create();

        $this->jsonClient($user)->request('GET', '/api/v1/messages/'.$message->getId().'/history');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousCannotGetMessageHistory(): void
    {
        $community = CommunityFactory::new()->withIdentifier('hist-c3')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'hist-ch3'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->create();

        $this->jsonClient()->request('GET', '/api/v1/messages/'.$message->getId().'/history');

        self::assertResponseStatusCodeSame(401);
    }

    public function testChannelModeratorCanGetMessageHistory(): void
    {
        $mod = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('hist-cmod')->create();
        CommunityMemberFactory::createForUserAndCommunity($mod, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'cmod-ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($mod, $channel, ChannelRole::Moderator);
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->create();

        $this->jsonClient($mod)->request('GET', '/api/v1/messages/'.$message->getId().'/history');

        self::assertResponseIsSuccessful();
    }

    public function testCommunityModeratorCanGetMessageHistory(): void
    {
        $mod = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('hist-commod')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'commod-ch'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->create();

        $this->jsonClient($mod)->request('GET', '/api/v1/messages/'.$message->getId().'/history');

        self::assertResponseIsSuccessful();
    }

    public function testCommunityAdminCanGetMessageHistory(): void
    {
        $admin = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('hist-cadm')->create();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'cadm-ch'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->create();

        $this->jsonClient($admin)->request('GET', '/api/v1/messages/'.$message->getId().'/history');

        self::assertResponseIsSuccessful();
    }

    public function testChannelModeratorCanGetHistoryOfSoftDeletedMessage(): void
    {
        $author = UserFactory::createOne();
        $mod = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('hist-del-cmod')->create();
        CommunityMemberFactory::createForUserAndCommunity($author, $community);
        CommunityMemberFactory::createForUserAndCommunity($mod, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'del-cmod-ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($mod, $channel, ChannelRole::Moderator);
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($author)->with(['deleted' => true])->create();

        $this->jsonClient($mod)->request('GET', '/api/v1/messages/'.$message->getId().'/history');

        self::assertResponseIsSuccessful();
    }

    public function testDeleteRecordsDeletedAtAndDeletedBy(): void
    {
        $author = UserFactory::createOne();
        $mod = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('hist-del-by')->create();
        CommunityMemberFactory::createForUserAndCommunity($author, $community);
        CommunityMemberFactory::createForUserAndCommunity($mod, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'del-by-ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($mod, $channel, ChannelRole::Moderator);
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($author)->create();

        $this->jsonClient($mod)->request('DELETE', '/api/v1/messages/'.$message->getId());
        self::assertResponseStatusCodeSame(204);

        $response = $this->jsonClient($mod)->request('GET', '/api/v1/messages/'.$message->getId());
        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertTrue($data['isDeleted']);
        self::assertNotNull($data['deletedAt']);
        self::assertNotNull($data['deletedBy']);
        // deletedBy serializes as a nested user object — assert identity.
        self::assertSame($mod->getId(), $data['deletedBy']['id']);
    }

    public function testCommunityModeratorCanGetMessageHistoryInArchivedChannel(): void
    {
        $mod = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('hist-arc-commod')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'arc-commod-ch'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->create();

        $channel->setArchivedAt(new \DateTimeImmutable());
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->persist($channel);
        $em->flush();

        $this->jsonClient($mod)->request('GET', '/api/v1/messages/'.$message->getId().'/history');

        self::assertResponseIsSuccessful();
    }

    public function testChannelModeratorCanGetMessageHistoryInArchivedChannel(): void
    {
        $mod = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('hist-arc-cmod')->create();
        CommunityMemberFactory::createForUserAndCommunity($mod, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'arc-cmod-ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($mod, $channel, ChannelRole::Moderator);
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->create();

        $channel->setArchivedAt(new \DateTimeImmutable());
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->persist($channel);
        $em->flush();

        $this->jsonClient($mod)->request('GET', '/api/v1/messages/'.$message->getId().'/history');

        self::assertResponseIsSuccessful();
    }

    public function testNonAdminCannotGetHistoryOfDmMessage(): void
    {
        // DM messages have no moderator audience — fall back to admin-only.
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $conversation = ConversationFactory::new()->withParticipants([$a, $b])->create();
        ConversationMemberFactory::createForUserAndConversation($a, $conversation);
        ConversationMemberFactory::createForUserAndConversation($b, $conversation);
        $page = MessagePageFactory::new()->forConversation($conversation)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($a)->create();

        $this->jsonClient($b)->request('GET', '/api/v1/messages/'.$message->getId().'/history');

        self::assertResponseStatusCodeSame(403);
    }
}
