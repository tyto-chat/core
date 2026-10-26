<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Service\MediaObject\MediaObjectServiceInterface;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class AttachmentThumbnailTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /** @return array<string, mixed> first attachment of a message posted with one image (a 1x1 PNG by default) */
    private function postImageAttachment(?string $bytes = null, string $name = 'test.png', string $mime = 'image/png'): array
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('th-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()
            ->inCommunity($community)
            ->with(['identifier' => 'th-ch', 'allowAttachments' => true])
            ->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);

        $tmp = tempnam(sys_get_temp_dir(), 'th_test_');
        file_put_contents((string) $tmp, $bytes ?? (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true));
        $file = new UploadedFile((string) $tmp, $name, $mime, null, true);

        $upload = $this->uploadClient($user)->request('POST', '/api/v1/communities/th-c/channels/th-ch/attachments', ['extra' => ['files' => ['file' => $file]]]);
        self::assertResponseStatusCodeSame(201);

        $message = $this->jsonClient($user)->request('POST', '/api/v1/communities/th-c/channels/th-ch/messages', [
            'json' => ['text' => 'thumb', 'attachmentIris' => [$upload->toArray()['@id']]],
        ]);
        self::assertResponseStatusCodeSame(201);

        return $message->toArray()['attachments'][0];
    }

    public function testImageAttachmentExposesServableThumbnailUrl(): void
    {
        $attachment = $this->postImageAttachment();

        $thumbPath = (string) parse_url((string) $attachment['thumbnailUrl'], PHP_URL_PATH);
        self::assertMatchesRegularExpression('#^/media/[0-9a-f]{64}\.[0-9]+/attachment_thumb/.+$#', $thumbPath);
        self::assertNotSame($attachment['contentUrl'], $attachment['thumbnailUrl']);

        static::createClient()->request('GET', $thumbPath);
        self::assertResponseStatusCodeSame(200);
    }

    public function testBackfillRecreatesMissingThumbnail(): void
    {
        $attachment = $this->postImageAttachment();
        $thumbPath = (string) parse_url((string) $attachment['thumbnailUrl'], PHP_URL_PATH);
        $file = self::getContainer()->getParameter('kernel.project_dir').'/var/media/attachment_thumb/'.basename($thumbPath);
        self::assertFileExists($file);
        unlink($file);

        $service = self::getContainer()->get(MediaObjectServiceInterface::class);
        self::assertSame(1, $service->generateMissingAttachmentThumbnails());
        self::assertFileExists($file);
        self::assertSame(0, $service->generateMissingAttachmentThumbnails());
    }

    public function testBackfillResumesAfterTheGivenId(): void
    {
        $attachment = $this->postImageAttachment();
        $attachmentId = (int) basename((string) $attachment['@id']);
        $thumbPath = (string) parse_url((string) $attachment['thumbnailUrl'], PHP_URL_PATH);
        $file = self::getContainer()->getParameter('kernel.project_dir').'/var/media/attachment_thumb/'.basename($thumbPath);
        unlink($file);

        $tester = new CommandTester((new Application(self::$kernel))->find('tyto:media:generate-thumbnails'));
        $tester->execute(['--from-id' => (string) $attachmentId]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Generated 0 thumbnails', $tester->getDisplay());
        self::assertFileDoesNotExist($file);

        $tester->execute(['--from-id' => (string) ($attachmentId - 1)]);
        self::assertStringContainsString('Generated 1 thumbnails', $tester->getDisplay());
        self::assertFileExists($file);
    }

    public function testAnimatedGifGetsAStillThumbnailAndIsFlagged(): void
    {
        $attachment = $this->postImageAttachment(self::gif(3), 'anim.gif', 'image/gif');

        self::assertTrue($attachment['animated']);
        $thumbPath = (string) parse_url((string) $attachment['thumbnailUrl'], PHP_URL_PATH);
        $file = self::getContainer()->getParameter('kernel.project_dir').'/var/media/attachment_thumb/'.basename($thumbPath);
        $thumb = new \Imagick($file);
        self::assertSame(1, $thumb->getNumberImages());
    }

    public function testStaticGifIsThumbnailedAndNotFlagged(): void
    {
        $attachment = $this->postImageAttachment(self::gif(1), 'still.gif', 'image/gif');

        self::assertFalse($attachment['animated']);
        self::assertNotNull($attachment['thumbnailUrl']);
    }

    public function testBackfillFlagsAndThumbnailsAnExistingGif(): void
    {
        $attachment = $this->postImageAttachment(self::gif(2), 'anim.gif', 'image/gif');
        $id = (int) basename((string) $attachment['@id']);
        $thumbPath = (string) parse_url((string) $attachment['thumbnailUrl'], PHP_URL_PATH);
        unlink(self::getContainer()->getParameter('kernel.project_dir').'/var/media/attachment_thumb/'.basename($thumbPath));
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $connection->executeStatement('UPDATE media_object SET animated = NULL WHERE id = ?', [$id]);
        self::getContainer()->get('doctrine.orm.entity_manager')->clear();

        $service = self::getContainer()->get(MediaObjectServiceInterface::class);
        self::assertSame(1, $service->generateMissingAttachmentThumbnails());

        self::assertSame(1, (int) $connection->fetchOne('SELECT animated FROM media_object WHERE id = ?', [$id]));
    }

    private static function gif(int $frames): string
    {
        $animation = new \Imagick();
        for ($i = 0; $i < $frames; ++$i) {
            $frame = new \Imagick();
            $frame->newImage(64, 48, 0 === $i % 2 ? 'red' : 'blue');
            $frame->setImageFormat('gif');
            $animation->addImage($frame);
        }
        $animation->setFormat('gif');

        return $animation->getImagesBlob();
    }
}
