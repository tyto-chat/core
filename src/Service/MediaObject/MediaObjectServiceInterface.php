<?php

declare(strict_types=1);

namespace App\Service\MediaObject;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\MediaObject;
use App\Entity\Message;

interface MediaObjectServiceInterface
{
    public const array FILTERS = [
        'avatar' => ['sm' => 'avatar_sm', 'md' => 'avatar_md', 'lg' => 'avatar_lg'],
        'logo' => ['sm' => 'logo_sm', 'md' => 'logo_md', 'lg' => 'logo_lg'],
        'community_emoji' => ['community_emoji'],
        'attachment' => ['attachment_thumb'],
    ];

    public const int MAX_THUMBNAIL_SOURCE_PIXELS = 50_000_000;

    public const array THUMBNAILABLE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * @internal no authz — caller must gate
     */
    public function prepare(MediaObject $mediaObject, string $type): void;

    public function populateFileMetadata(MediaObject $mediaObject): void;

    /** @internal No authz — caller must gate. */
    public function delete(MediaObject $mediaObject): void;

    public function linkAttachmentToMessage(MediaObject $attachment, Message $message): void;

    public function deleteAttachment(MediaObject $attachment): void;

    public function deleteMessageAttachments(Message $message): void;

    /** Removes one attachment row + file and stamps the tombstone counter. No flush — caller flushes. */
    public function purgeAttachment(MediaObject $attachment): void;

    /** @internal No authz — caller must gate. Bulk rows + files cleanup before channel deletion. */
    public function deleteChannelAttachments(Channel $channel): void;

    /** @internal No authz — caller must gate. Bulk rows + files cleanup before community deletion. */
    public function deleteCommunityAttachments(Community $community): void;

    /** @internal No authz — caller must gate. Creates missing attachment thumbnails; returns how many were generated. */
    /**
     * @param (callable(int): void)|null $onAttachment called with each attachment id before it is processed
     */
    public function generateMissingAttachmentThumbnails(?callable $onAttachment = null, int $fromId = 0): int;

    public function getByFilePath(string $filePath): MediaObject;

    public function getTotalStoredBytes(): int;
}
