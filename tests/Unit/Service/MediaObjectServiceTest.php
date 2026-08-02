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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Filesystem\Filesystem;
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
