<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\Message;
use App\Entity\User;
use App\Enum\Channel\ChannelType;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ChannelArchiveTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testCommunityAdminCanArchiveAndUnarchive(): void
    {
        $admin = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('arc-c')->create();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'arc-ch'])->create();

        $client = $this->jsonClient($admin);
        $client->request('POST', '/api/v1/communities/arc-c/channels/arc-ch/archive');
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/v1/communities/arc-c');
        $channels = $client->getResponse()->toArray()['channels'];
        self::assertNotNull($channels[0]['archivedAt']);

        $client->request('POST', '/api/v1/communities/arc-c/channels/arc-ch/unarchive');
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/v1/communities/arc-c');
        $channels = $client->getResponse()->toArray()['channels'];
        self::assertNull($channels[0]['archivedAt']);
    }

    public function testRegularMemberCannotArchive(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('arc-deny')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ch'])->create();

        $this->jsonClient($user)->request('POST', '/api/v1/communities/arc-deny/channels/ch/archive');
        self::assertResponseStatusCodeSame(403);
    }

    public function testArchivingAudioChannelRejected(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('arc-audio')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'voice', 'type' => ChannelType::Audio])->create();

        $this->jsonClient($admin)->request('POST', '/api/v1/communities/arc-audio/channels/voice/archive');
        self::assertResponseStatusCodeSame(422);
    }

    public function testArchivingWelcomeChannelRejected(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('arc-wel')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'welcome'])->create();
        $community->setWelcomeChannel($channel);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->persist($community);
        $em->flush();

        $this->jsonClient($admin)->request('POST', '/api/v1/communities/arc-wel/channels/welcome/archive');
        self::assertResponseStatusCodeSame(422);
    }

    public function testDoubleArchiveRejected(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('arc-dbl')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ch', 'archivedAt' => new \DateTimeImmutable()])->create();

        $this->jsonClient($admin)->request('POST', '/api/v1/communities/arc-dbl/channels/ch/archive');
        self::assertResponseStatusCodeSame(422);
    }

    public function testUnarchiveOnActiveChannelRejected(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('arc-act')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ch'])->create();

        $this->jsonClient($admin)->request('POST', '/api/v1/communities/arc-act/channels/ch/unarchive');
        self::assertResponseStatusCodeSame(422);
    }

    /** @return array{Channel, Message, User, Community} */
    private function archivedFixture(string $id): array
    {
        $author = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier($id)->create();
        CommunityMemberFactory::createForUserAndCommunity($author, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ch'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($author)->create();

        $channel->setArchivedAt(new \DateTimeImmutable());
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->persist($channel);
        $em->flush();

        return [$channel, $message, $author, $community];
    }

    public function testMemberCannotPostInArchivedChannel(): void
    {
        [, , $author, $community] = $this->archivedFixture('frz-post');
        $this->jsonClient($author)->request('POST', '/api/v1/communities/frz-post/channels/ch/messages', [
            'json' => ['text' => 'hello'],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testGlobalAdminCannotPostInArchivedChannel(): void
    {
        $this->archivedFixture('frz-adm');
        $admin = UserFactory::new()->admin()->create();
        $this->jsonClient($admin)->request('POST', '/api/v1/communities/frz-adm/channels/ch/messages', [
            'json' => ['text' => 'hello'],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAuthorCannotEditMessageInArchivedChannel(): void
    {
        [, $message, $author] = $this->archivedFixture('frz-edit');
        $this->jsonClient($author)->request('PATCH', '/api/v1/messages/'.$message->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['text' => 'edited'],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAuthorCannotDeleteMessageInArchivedChannel(): void
    {
        [, $message, $author] = $this->archivedFixture('frz-del');
        $this->jsonClient($author)->request('DELETE', '/api/v1/messages/'.$message->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testCannotReactInArchivedChannel(): void
    {
        [, $message, $author] = $this->archivedFixture('frz-react');
        $this->jsonClient($author)->request('POST', '/api/v1/messages/'.$message->getId().'/reactions', [
            'json' => ['emoji' => '👍'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCannotReplyInArchivedChannel(): void
    {
        [, $message, $author] = $this->archivedFixture('frz-reply');
        $this->jsonClient($author)->request('POST', '/api/v1/messages/'.$message->getId().'/replies', [
            'json' => ['text' => 'reply'],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCannotPinInArchivedChannel(): void
    {
        [, $message] = $this->archivedFixture('frz-pin');
        $admin = UserFactory::new()->admin()->create();
        $this->jsonClient($admin)->request('POST', '/api/v1/messages/'.$message->getId().'/pin');
        self::assertResponseStatusCodeSame(403);
    }

    public function testViewingArchivedChannelStillWorks(): void
    {
        [, , $author] = $this->archivedFixture('frz-view');
        $this->jsonClient($author)->request('GET', '/api/v1/communities/frz-view/channels/ch/messages/current');
        self::assertResponseIsSuccessful();
    }
}
