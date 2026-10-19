<?php

declare(strict_types=1);

namespace App\Dto\Message;

final readonly class MessageEmbedDto
{
    /**
     * @param list<array{url: string, video: bool, name: string}>     $media
     * @param list<array{key: string, imageUrl: ?string, count: int}> $reactions
     */
    public function __construct(
        public string $uuid,
        public string $authorName,
        public int $authorColor,
        public ?string $avatarUrl,
        public \DateTimeInterface $createdAt,
        public string $bodyHtml,
        public string $plaintext,
        public array $media,
        public int $extraMediaCount,
        public int $fileCount,
        public array $reactions,
        public int $replyCount,
        public string $channelIdentifier,
        public string $communityName,
    ) {
    }
}
