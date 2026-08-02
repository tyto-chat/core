<?php

declare(strict_types=1);

namespace App\Service\MediaObject;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\MediaObject;
use App\Entity\Message;
use App\Exception\MediaObject\AttachmentAlreadyLinkedException;
use App\Exception\MediaObject\MediaObjectNotFoundException;
use App\Exception\MediaObject\NotAnAttachmentException;
use App\Repository\MediaObjectRepository;
use App\Security\SecurityContext;
use App\Security\Voter\MediaObjectVoter;
use App\Service\AbstractDoctrineService;
use App\Service\Community\CommunityEmojiServiceInterface;
use App\Service\Realtime\MessageRealtimePublisherInterface;
use Liip\ImagineBundle\Imagine\Filter\FilterManager;
use Liip\ImagineBundle\Model\Binary;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Lazy;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class MediaObjectService extends AbstractDoctrineService implements MediaObjectServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly FilterManager $filterManager,
        private readonly Filesystem $filesystem,
        private readonly MessageRealtimePublisherInterface $publisher,
        private readonly MediaObjectRepository $mediaObjectRepository,
        #[Lazy]
        private readonly CommunityEmojiServiceInterface $communityEmojiService,
        #[Autowire('%kernel.project_dir%/var/media')] private readonly string $mediaDir,
    ) {
    }

    #[\Override]
    public function prepare(MediaObject $mediaObject, string $type): void
    {
        $mediaObject->type = $type;
        $this->generateVariants($mediaObject);
    }

    #[\Override]
    public function populateFileMetadata(MediaObject $mediaObject): void
    {
        if (!$mediaObject->file instanceof UploadedFile) {
            return;
        }

        $mediaObject->originalName = $mediaObject->file->getClientOriginalName();
        $mediaObject->mimeType = $mediaObject->file->getMimeType();
        $size = $mediaObject->file->getSize();
        $mediaObject->size = false === $size || 0 === $size ? null : $size;

        if (str_starts_with((string) $mediaObject->mimeType, 'image/')) {
            $imageSize = @getimagesize($mediaObject->file->getPathname());
            if (false !== $imageSize) {
                $mediaObject->width = $imageSize[0];
                $mediaObject->height = $imageSize[1];
            }
        }
    }

    #[\Override]
    public function delete(MediaObject $mediaObject): void
    {
        $emoji = $this->communityEmojiService->findByImage($mediaObject);
        if (null !== $emoji) {
            // Guards the image_id SET NULL orphan: emoji deletion cascades reactions.
            $this->communityEmojiService->delete($emoji);

            return;
        }

        $this->deleteVariants($mediaObject);
        $this->removeAndFlush($mediaObject);
    }

    #[\Override]
    public function linkAttachmentToMessage(MediaObject $attachment, Message $message): void
    {
        if ('attachment' !== $attachment->type) {
            throw new NotAnAttachmentException('Media object is not an attachment.');
        }

        $this->security->throwAccessDeniedUnlessGranted(MediaObjectVoter::LINK, $attachment, sprintf('You do not have permission to link attachment "%s".', $attachment->getId()));

        if (null !== $attachment->message) {
            throw new AttachmentAlreadyLinkedException(sprintf('Attachment "%s" is already linked to another message.', $attachment->getId()));
        }

        $attachment->message = $message;
        $message->getAttachments()->add($attachment);
        $this->save($attachment);
    }

    #[\Override]
    public function deleteAttachment(MediaObject $attachment): void
    {
        $this->security->throwAccessDeniedUnlessGranted(MediaObjectVoter::DELETE, $attachment, 'You do not have permission to delete this attachment.');

        $parentMessage = $attachment->message;
        // VichUploader's preRemove listener deletes the file — bulk DQL deletes would skip it.
        $this->removeAndFlush($attachment);

        if (null !== $parentMessage) {
            $this->publisher->publishMessageAttachmentsUpdated($parentMessage);
        }
    }

    #[\Override]
    public function getByFilePath(string $filePath): MediaObject
    {
        $mediaObject = $this->mediaObjectRepository->findByFilePath($filePath);
        if (null === $mediaObject) {
            throw new MediaObjectNotFoundException('Media object not found.');
        }

        $this->security->throwAccessDeniedUnlessGranted(MediaObjectVoter::VIEW, $mediaObject);

        return $mediaObject;
    }

    #[\Override]
    public function purgeAttachment(MediaObject $attachment): void
    {
        $message = $attachment->message;

        // VichUploader's preRemove listener deletes the file — bulk DQL deletes would skip it.
        $this->entityManager->remove($attachment);

        if (null !== $message) {
            $message->getAttachments()->removeElement($attachment);
            $message->setPurgedAttachmentCount($message->getPurgedAttachmentCount() + 1);
            $this->persist($message);
        }
    }

    #[\Override]
    public function deleteMessageAttachments(Message $message): void
    {
        $paths = $this->mediaObjectRepository->findFilePathsForMessage($message);
        $deleted = $this->mediaObjectRepository->deleteRowsForMessage($message);
        $this->removeFiles($paths);

        // Bulk DQL bypasses the unit of work — evict the stale managed entities.
        foreach ($message->getAttachments() as $attachment) {
            $this->entityManager->detach($attachment);
        }
        $message->getAttachments()->clear();

        if ($deleted > 0) {
            $message->setPurgedAttachmentCount($message->getPurgedAttachmentCount() + $deleted);
            $this->persist($message);
        }
    }

    #[\Override]
    public function deleteChannelAttachments(Channel $channel): void
    {
        $paths = $this->mediaObjectRepository->findFilePathsForChannel($channel);
        $this->mediaObjectRepository->deleteRowsForChannel($channel);
        $this->removeFiles($paths);
    }

    #[\Override]
    public function deleteCommunityAttachments(Community $community): void
    {
        $paths = $this->mediaObjectRepository->findFilePathsForCommunity($community);
        $this->mediaObjectRepository->deleteRowsForCommunity($community);
        $this->removeFiles($paths);
    }

    /** @param list<string> $paths */
    private function removeFiles(array $paths): void
    {
        $this->filesystem->remove(array_map(
            fn (string $path): string => $this->mediaDir.'/'.$path,
            $paths,
        ));
    }

    private function generateVariants(MediaObject $mediaObject): void
    {
        if (null === $mediaObject->filePath || null === $mediaObject->type) {
            return;
        }

        $filters = self::FILTERS[$mediaObject->type] ?? [];
        $originalPath = $this->mediaDir.'/'.$mediaObject->filePath;
        $mimeType = (string) mime_content_type($originalPath);
        $format = substr($mimeType, (int) strrpos($mimeType, '/') + 1);
        $isAnimatedGif = $this->isAnimatedGif($originalPath, $mimeType);

        if ($isAnimatedGif) {
            // Liip's thumbnail filter flattens animated GIFs to one frame — copy the original bytes instead.
            foreach ($filters as $filter) {
                $outputPath = $this->mediaDir.'/'.$filter.'/'.$mediaObject->filePath;
                $this->filesystem->copy($originalPath, $outputPath, true);
            }

            return;
        }

        $binary = new Binary((string) file_get_contents($originalPath), $mimeType, $format);

        foreach ($filters as $filter) {
            $filtered = $this->filterManager->applyFilter($binary, $filter);
            $outputPath = $this->mediaDir.'/'.$filter.'/'.$mediaObject->filePath;
            $this->filesystem->dumpFile($outputPath, $filtered->getContent());
        }
    }

    private function isAnimatedGif(string $path, string $mimeType): bool
    {
        if ('image/gif' !== $mimeType) {
            return false;
        }
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            return false;
        }
        $found = false;
        try {
            $chunk = '';
            while (!feof($handle)) {
                $chunk .= (string) fread($handle, 65536);
                if (false !== strpos($chunk, 'NETSCAPE2.0')) {
                    $found = true;
                    break;
                }
                // Keep a small tail in case the marker straddles two chunks.
                if (strlen($chunk) > 131072) {
                    $chunk = substr($chunk, -32);
                }
            }
        } finally {
            fclose($handle);
        }

        return $found;
    }

    private function deleteVariants(MediaObject $mediaObject): void
    {
        if (null === $mediaObject->filePath || null === $mediaObject->type) {
            return;
        }

        $paths = array_map(
            fn (string $filter) => $this->mediaDir.'/'.$filter.'/'.$mediaObject->filePath,
            self::FILTERS[$mediaObject->type] ?? [],
        );
        $this->filesystem->remove($paths);
    }

    #[\Override]
    public function getTotalStoredBytes(): int
    {
        return $this->mediaObjectRepository->getTotalSize();
    }
}
