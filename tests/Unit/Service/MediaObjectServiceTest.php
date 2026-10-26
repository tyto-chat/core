<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\CommunityEmoji;
use App\Entity\MediaObject;
use App\Entity\Message;
use App\Entity\User;
use App\Exception\MediaObject\AttachmentAlreadyLinkedException;
use App\Exception\MediaObject\MediaObjectNotFoundException;
use App\Exception\MediaObject\NotAnAttachmentException;
use App\Repository\MediaObjectRepository;
use App\Security\SecurityContext;
use App\Service\Community\CommunityEmojiServiceInterface;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\MediaObject\MediaObjectService;
use App\Service\Realtime\RealtimePublisherInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Liip\ImagineBundle\Imagine\Filter\FilterManager;
use Liip\ImagineBundle\Model\Binary;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AllowMockObjectsWithoutExpectations]
class MediaObjectServiceTest extends TestCase
{
    private FilterManager&MockObject $filterManager;
    private Filesystem&MockObject $filesystem;
    private MediaObjectRepository&MockObject $mediaObjectRepository;
    private CommunityEmojiServiceInterface&MockObject $communityEmojiService;
    private EntityManagerInterface&MockObject $entityManager;
    private Security&MockObject $security;
    private MediaObjectService $service;

    private function setMediaObjectCreatedBy(MediaObject $mediaObject, User $user): void
    {
        $ref = new \ReflectionProperty(MediaObject::class, 'createdBy');
        $ref->setValue($mediaObject, $user);
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->filterManager = $this->createMock(FilterManager::class);
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->mediaObjectRepository = $this->createMock(MediaObjectRepository::class);
        $this->communityEmojiService = $this->createMock(CommunityEmojiServiceInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->security = $this->createMock(Security::class);

        $publisher = $this->createMock(RealtimePublisherInterface::class);
        $this->service = new MediaObjectService(new SecurityContext($this->security, $this->createMock(CommunityMembershipServiceInterface::class)), $this->filterManager, $this->filesystem, $publisher, $this->mediaObjectRepository, $this->communityEmojiService, '/tmp/media');
        $this->service->setEntityManager($this->entityManager);
    }

    public function testPrepareSetsType(): void
    {
        $mediaObject = new MediaObject();

        $this->service->prepare($mediaObject, 'avatar');

        self::assertSame('avatar', $mediaObject->type);
    }

    public function testPrepareLogoSetsType(): void
    {
        $mediaObject = new MediaObject();

        $this->service->prepare($mediaObject, 'logo');

        self::assertSame('logo', $mediaObject->type);
    }

    public function testPrepareAttachmentWritesThumbnailForRasterImage(): void
    {
        $dir = $this->mediaDirWith('a.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
        $service = $this->serviceWithMediaDir($dir);
        $mediaObject = new MediaObject();
        $mediaObject->filePath = 'a.png';

        $this->filterManager->expects(self::once())->method('applyFilter')
            ->with(self::isInstanceOf(Binary::class), 'attachment_thumb')
            ->willReturn(new Binary('thumb', 'image/png', 'png'));
        $this->filesystem->expects(self::once())->method('dumpFile')->with($dir.'/attachment_thumb/a.png', 'thumb');

        $service->prepare($mediaObject, 'attachment');

        self::assertSame('attachment', $mediaObject->type);
    }

    public function testPrepareAttachmentSkipsNonImages(): void
    {
        $dir = $this->mediaDirWith('notes.txt', 'plain text');
        $service = $this->serviceWithMediaDir($dir);
        $mediaObject = new MediaObject();
        $mediaObject->filePath = 'notes.txt';

        $this->filterManager->expects(self::never())->method('applyFilter');
        $this->filesystem->expects(self::never())->method('dumpFile');

        $service->prepare($mediaObject, 'attachment');
    }

    public function testPrepareAttachmentSkipsThumbnailWhenPixelCountExceedsLimit(): void
    {
        $header = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 20000, 20000)."\x08\x02\x00\x00\x00".pack('N', 0);
        $dir = $this->mediaDirWith('bomb.png', $header);
        $service = $this->serviceWithMediaDir($dir);
        $mediaObject = new MediaObject();
        $mediaObject->filePath = 'bomb.png';

        $this->filterManager->expects(self::never())->method('applyFilter');
        $this->filesystem->expects(self::never())->method('dumpFile');

        $service->prepare($mediaObject, 'attachment');

        self::assertSame('attachment', $mediaObject->type);
    }

    public function testPrepareAttachmentThumbnailsOnlyTheFirstFrameOfAnAnimatedGif(): void
    {
        $dir = $this->mediaDirWith('pic.gif', self::gif(['red', 'blue', 'green']));
        $service = $this->serviceWithMediaDir($dir);
        $mediaObject = new MediaObject();
        $mediaObject->filePath = 'pic.gif';

        $source = null;
        $this->filterManager->expects(self::once())->method('applyFilter')
            ->with(self::isInstanceOf(Binary::class), 'attachment_thumb')
            ->willReturnCallback(static function (Binary $binary) use (&$source): Binary {
                $source = $binary;

                return new Binary('thumb', 'image/gif', 'gif');
            });
        $this->filesystem->expects(self::never())->method('copy');
        $this->filesystem->expects(self::once())->method('dumpFile')->with($dir.'/attachment_thumb/pic.gif', 'thumb');

        $service->prepare($mediaObject, 'attachment');

        self::assertInstanceOf(Binary::class, $source);
        self::assertSame('image/gif', $source->getMimeType());
        $decoded = new \Imagick();
        $decoded->readImageBlob($source->getContent());
        self::assertSame(1, $decoded->getNumberImages());
        self::assertSame('red', self::dominantColour($decoded));
    }

    public function testPrepareAttachmentThumbnailsAStaticGif(): void
    {
        $dir = $this->mediaDirWith('still.gif', self::gif(['blue']));
        $service = $this->serviceWithMediaDir($dir);
        $mediaObject = new MediaObject();
        $mediaObject->filePath = 'still.gif';

        $this->filterManager->expects(self::once())->method('applyFilter')->willReturn(new Binary('thumb', 'image/gif', 'gif'));
        $this->filesystem->expects(self::once())->method('dumpFile')->with($dir.'/attachment_thumb/still.gif', 'thumb');

        $service->prepare($mediaObject, 'attachment');
    }

    public function testPrepareAvatarKeepsAnimatedGifsWhole(): void
    {
        $dir = $this->mediaDirWith('pic.gif', "GIF89a\x01\x00\x01\x00\x00\x00\x00!\xff\x0bNETSCAPE2.0\x03\x01\x00\x00\x00;");
        $service = $this->serviceWithMediaDir($dir);
        $mediaObject = new MediaObject();
        $mediaObject->filePath = 'pic.gif';

        $this->filterManager->expects(self::never())->method('applyFilter');
        $this->filesystem->expects(self::exactly(3))->method('copy');

        $service->prepare($mediaObject, 'avatar');
    }

    /** @return iterable<string, array{string, string, ?bool}> */
    public static function uploads(): iterable
    {
        yield 'animated gif' => ['pic.gif', self::gif(['red', 'blue']), true];
        yield 'static gif' => ['still.gif', self::gif(['red']), false];
        yield 'png' => ['a.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true), null];
    }

    #[DataProvider('uploads')]
    public function testPopulateFileMetadataFlagsAnimatedGifs(string $name, string $bytes, ?bool $animated): void
    {
        $dir = $this->mediaDirWith($name, $bytes);
        $mediaObject = new MediaObject();
        $mediaObject->file = new UploadedFile($dir.'/'.$name, $name, null, null, true);

        $this->service->populateFileMetadata($mediaObject);

        self::assertSame($animated, $mediaObject->animated);
    }

    /** @param list<string> $colours */
    private static function gif(array $colours): string
    {
        $animation = new \Imagick();
        foreach ($colours as $colour) {
            $frame = new \Imagick();
            $frame->newImage(40, 30, $colour);
            $frame->setImageFormat('gif');
            $animation->addImage($frame);
        }
        $animation->setFormat('gif');

        return $animation->getImagesBlob();
    }

    private static function dominantColour(\Imagick $image): string
    {
        $pixel = $image->getImagePixelColor(0, 0)->getColor();

        return match (true) {
            $pixel['r'] > 200 && $pixel['b'] < 50 => 'red',
            $pixel['b'] > 200 && $pixel['r'] < 50 => 'blue',
            default => 'other',
        };
    }

    public function testPrepareAttachmentSurvivesThumbnailFailure(): void
    {
        $dir = $this->mediaDirWith('a.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
        $service = $this->serviceWithMediaDir($dir);
        $mediaObject = new MediaObject();
        $mediaObject->filePath = 'a.png';

        $this->filterManager->method('applyFilter')->willThrowException(new \RuntimeException('corrupt'));
        $this->filesystem->expects(self::never())->method('dumpFile');

        $service->prepare($mediaObject, 'attachment');

        self::assertSame('attachment', $mediaObject->type);
    }

    public function testPrepareAttachmentSurvivesThumbnailWriteFailure(): void
    {
        $dir = $this->mediaDirWith('a.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
        $service = $this->serviceWithMediaDir($dir);
        $mediaObject = new MediaObject();
        $mediaObject->filePath = 'a.png';

        $this->filterManager->method('applyFilter')->willReturn(new Binary('thumb', 'image/png', 'png'));
        $this->filesystem->method('dumpFile')->willThrowException(new \RuntimeException('disk full'));

        $service->prepare($mediaObject, 'attachment');

        self::assertSame('attachment', $mediaObject->type);
    }

    public function testPrepareNonAttachmentStillPropagatesVariantFailures(): void
    {
        $dir = $this->mediaDirWith('a.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
        $service = $this->serviceWithMediaDir($dir);
        $mediaObject = new MediaObject();
        $mediaObject->filePath = 'a.png';

        $this->filterManager->method('applyFilter')->willThrowException(new \RuntimeException('corrupt'));

        $this->expectException(\RuntimeException::class);
        $service->prepare($mediaObject, 'avatar');
    }

    private function mediaDirWith(string $name, string $contents): string
    {
        $dir = sys_get_temp_dir().'/tyto-media-'.bin2hex(random_bytes(4));
        mkdir($dir, 0o777, true);
        file_put_contents($dir.'/'.$name, $contents);

        return $dir;
    }

    private function serviceWithMediaDir(string $dir): MediaObjectService
    {
        $service = new MediaObjectService(new SecurityContext($this->security, $this->createMock(CommunityMembershipServiceInterface::class)), $this->filterManager, $this->filesystem, $this->createMock(RealtimePublisherInterface::class), $this->mediaObjectRepository, $this->communityEmojiService, $dir);
        $service->setEntityManager($this->entityManager);
        $service->setLogger($this->createMock(LoggerInterface::class));

        return $service;
    }

    public function testLinkAttachmentToMessageThrowsWhenNotAttachmentType(): void
    {
        $attachment = new MediaObject();
        $attachment->type = 'avatar';
        $message = $this->createMock(Message::class);

        $this->expectException(NotAnAttachmentException::class);
        $this->service->linkAttachmentToMessage($attachment, $message);
    }

    public function testLinkAttachmentToMessageThrowsWhenUserDoesNotOwnIt(): void
    {
        $owner = $this->createMock(User::class);
        $other = $this->createMock(User::class);

        $attachment = new MediaObject();
        $attachment->type = 'attachment';
        $this->setMediaObjectCreatedBy($attachment, $owner);

        $this->security->method('getUser')->willReturn($other);
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->linkAttachmentToMessage($attachment, $this->createMock(Message::class));
    }

    public function testLinkAttachmentToMessageThrowsWhenAlreadyLinked(): void
    {
        $user = $this->createMock(User::class);

        $attachment = new MediaObject();
        $attachment->type = 'attachment';
        $this->setMediaObjectCreatedBy($attachment, $user);
        $attachment->message = $this->createMock(Message::class);

        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')->willReturn(true);

        $this->expectException(AttachmentAlreadyLinkedException::class);
        $this->service->linkAttachmentToMessage($attachment, $this->createMock(Message::class));
    }

    public function testLinkAttachmentToMessageLinksAndPersists(): void
    {
        $user = $this->createMock(User::class);

        $attachment = new MediaObject();
        $attachment->type = 'attachment';
        $this->setMediaObjectCreatedBy($attachment, $user);

        $message = $this->createMock(Message::class);
        $message->method('getAttachments')->willReturn(new ArrayCollection());

        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')->willReturn(true);

        $this->entityManager->expects(self::once())->method('persist')->with($attachment);
        $this->entityManager->expects(self::once())->method('flush');

        $this->service->linkAttachmentToMessage($attachment, $message);

        self::assertSame($message, $attachment->message);
    }

    public function testDeleteAttachmentThrowsWhenNonOwnerDeletesPending(): void
    {
        $owner = $this->createMock(User::class);
        $other = $this->createMock(User::class);

        $attachment = new MediaObject();
        $attachment->type = 'attachment';
        $this->setMediaObjectCreatedBy($attachment, $owner);

        $this->security->method('getUser')->willReturn($other);
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->deleteAttachment($attachment);
    }

    public function testDeleteAttachmentAllowsOwnerToDeletePending(): void
    {
        $user = $this->createMock(User::class);

        $attachment = new MediaObject();
        $attachment->type = 'attachment';
        $this->setMediaObjectCreatedBy($attachment, $user);

        // MediaObjectVoter::DELETE is the source of truth — service just delegates.
        $this->security->method('isGranted')->willReturn(true);

        $this->entityManager->expects(self::once())->method('remove')->with($attachment);
        $this->entityManager->expects(self::once())->method('flush');

        $this->service->deleteAttachment($attachment);
    }

    public function testDeleteRoutesEmojiImageThroughEmojiService(): void
    {
        $mediaObject = new MediaObject();
        $emoji = new CommunityEmoji();
        $this->communityEmojiService->method('findByImage')->willReturn($emoji);
        $this->communityEmojiService->expects(self::once())->method('delete')->with($emoji);
        $this->entityManager->expects(self::never())->method('remove');

        $this->service->delete($mediaObject);
    }

    public function testDeleteRemovesPlainMediaObject(): void
    {
        $mediaObject = new MediaObject();
        $this->communityEmojiService->method('findByImage')->willReturn(null);
        $this->entityManager->expects(self::once())->method('remove')->with($mediaObject);
        $this->entityManager->expects(self::once())->method('flush');

        $this->service->delete($mediaObject);
    }

    public function testGetByFilePathThrowsNotFoundWhenMissing(): void
    {
        $this->mediaObjectRepository->method('findByFilePath')->willReturn(null);

        $this->expectException(MediaObjectNotFoundException::class);
        $this->service->getByFilePath('missing.jpg');
    }

    public function testGetByFilePathThrowsAccessDeniedWhenVoterDenies(): void
    {
        $mediaObject = new MediaObject();
        $this->mediaObjectRepository->method('findByFilePath')->willReturn($mediaObject);
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->getByFilePath('secret.jpg');
    }

    public function testGetByFilePathReturnsMediaObjectWhenGranted(): void
    {
        $mediaObject = new MediaObject();
        $this->mediaObjectRepository->method('findByFilePath')->willReturn($mediaObject);
        $this->security->method('isGranted')->willReturn(true);

        self::assertSame($mediaObject, $this->service->getByFilePath('photo.jpg'));
    }
}
