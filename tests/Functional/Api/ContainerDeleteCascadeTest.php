<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\MediaObject;
use App\Entity\Message;
use App\Entity\MessageRevision;
use App\Entity\Reaction;
use App\Entity\User;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ContainerDeleteCascadeTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /** @return array{User, User, Community, Channel} */
    private function setupChannelWithContent(string $communityId, string $channelId): array
    {
        $admin = UserFactory::createOne();
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier($communityId)->create();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => $channelId])->create();
        ChannelMemberFactory::createForUserAndChannel($member, $channel);

        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $root = MessageFactory::new()->inPage($page)->byUser($member)->create();
        MessageFactory::new()->inPage($page)->byUser($member)->with(['parent' => $root])->create();

        $this->jsonClient($member)->request('POST', '/api/v1/messages/'.$root->getId().'/reactions', [
            'json' => ['emoji' => '👍'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $tmp = tempnam(sys_get_temp_dir(), 'casc_test_');
        file_put_contents((string) $tmp, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true));
        $file = new UploadedFile((string) $tmp, 'casc.png', 'image/png', null, true);
        $response = $this->uploadClient($member)->request(
            'POST',
            "/api/v1/communities/{$communityId}/channels/{$channelId}/attachments",
            ['extra' => ['files' => ['file' => $file]]],
        );
        self::assertResponseStatusCodeSame(201);
        $attachmentIri = (string) $response->toArray()['@id'];

        $this->jsonClient($member)->request(
            'POST',
            "/api/v1/communities/{$communityId}/channels/{$channelId}/messages",
            ['json' => ['text' => 'with attachment', 'attachmentIris' => [$attachmentIri]]],
        );
        self::assertResponseStatusCodeSame(201);

        return [$admin, $member, $community, $channel];
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function attachmentFilePath(): string
    {
        $attachment = $this->em()->getRepository(MediaObject::class)->findOneBy(['type' => 'attachment']);
        self::assertInstanceOf(MediaObject::class, $attachment);
        self::assertNotNull($attachment->filePath);

        return static::getContainer()->getParameter('kernel.project_dir').'/var/media/'.$attachment->filePath;
    }

    private function assertNoContentRowsRemain(EntityManagerInterface $em): void
    {
        self::assertSame(0, $em->getRepository(Message::class)->count([]));
        self::assertSame(0, $em->getRepository(MessageRevision::class)->count([]));
        self::assertSame(0, $em->getRepository(Reaction::class)->count([]));
        self::assertSame(0, $em->getRepository(MediaObject::class)->count([]));
    }

    public function testDeleteChannelWithContentCascades(): void
    {
        [$admin] = $this->setupChannelWithContent('casc-ch-c', 'casc-ch');
        $filePath = $this->attachmentFilePath();
        self::assertFileExists($filePath);

        $this->jsonClient($admin)->request('DELETE', '/api/v1/communities/casc-ch-c/channels/casc-ch');

        self::assertResponseStatusCodeSame(204);
        $em = $this->em();
        $em->clear();
        $this->assertNoContentRowsRemain($em);
        self::assertFileDoesNotExist($filePath);
    }

    public function testDeleteCommunityWithContentCascades(): void
    {
        $this->setupChannelWithContent('casc-cm-c', 'casc-cm-ch');
        $globalAdmin = UserFactory::new()->admin()->create();
        $filePath = $this->attachmentFilePath();
        self::assertFileExists($filePath);

        $this->jsonClient($globalAdmin)->request('DELETE', '/api/v1/communities/casc-cm-c');

        self::assertResponseStatusCodeSame(204);
        $em = $this->em();
        $em->clear();
        $this->assertNoContentRowsRemain($em);
        self::assertSame(0, $em->getRepository(Channel::class)->count([]));
        self::assertFileDoesNotExist($filePath);
    }
}
